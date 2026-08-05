<?php

namespace App\Controller;

use App\Engine\CitationEngineClient;
use App\Engine\CitationEngineException;
use App\Engine\Dto\ResolveResult;
use App\Entity\AnalysisRun;
use App\Entity\Article;
use App\Entity\Researcher;
use App\Entity\User;
use App\Enum\AnalysisSource;
use App\Enum\ArticleSort;
use App\Enum\AuditAction;
use App\Enum\RunStatus;
use App\Export\RunWorkbook;
use App\Form\Model\NewAnalysisInput;
use App\Form\NewAnalysisType;
use App\Health\PreflightCheck;
use App\Health\ServiceHealthChecker;
use App\Repository\AnalysisRunRepository;
use App\Repository\ArticleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\ResearcherRepository;
use App\Service\AnalysisRunner;
use App\Service\ResearcherRegistry;
use App\Service\SourceCatalog;
use App\Service\StalledRunReaper;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Launching an analysis and reading its result.
 *
 * The launch is a two-step flow when the engine cannot resolve the query to a
 * single author: a name search returns candidates and the user picks one, so the
 * researcher a run belongs to is always an explicit choice.
 */
final class AnalysisController extends AbstractController
{
    /** Runs per page on the history screen. */
    private const PER_PAGE = 25;

    /**
     * Articles per page on a run's detail page. A prolific author's run carries
     * ~165 articles and ~1,470 citing works; rendering them at once hydrated 1,600+
     * entities and put as many nodes in the DOM for a page nobody reads end to end.
     */
    private const ARTICLES_PER_PAGE = 25;

    /**
     * The full run history: every analysis ever launched, filterable by researcher
     * and status. The dashboard shows only the newest handful and links here.
     */
    #[Route('/analisis', name: 'app_run_index', methods: ['GET'])]
    public function index(Request $request, AnalysisRunRepository $runs, StalledRunReaper $reaper): Response
    {
        $reaper->sweepThrottled();

        $search = trim((string) $request->query->get('q'));
        $status = RunStatus::tryFrom((string) $request->query->get('estado'));
        $archived = $request->query->getBoolean('archivados');

        $total = $runs->countHistory($search, $status, $archived);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        // Clamped rather than 404'd: a stale ?p= (or a filter that just shrank the
        // result set) should land on the last page, not on an empty table.
        $page = min(max(1, $request->query->getInt('p', 1)), $pages);

        return $this->render('analysis/index.html.twig', [
            'runs' => $runs->findHistory($search, $status, $page, self::PER_PAGE, $archived),
            'search' => $search,
            'status' => $status,
            'counts' => $runs->countsByStatus($search),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'archived' => $archived,
            'archived_total' => $runs->countArchived(),
        ]);
    }

    #[Route('/analisis/nuevo', name: 'app_analysis_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CitationEngineClient $engine,
        ResearcherRegistry $registry,
        AnalysisRunner $runner,
        ServiceHealthChecker $health,
        ResearcherRepository $researchers,
        SourceCatalog $catalog,
    ): Response {
        $input = new NewAnalysisInput();
        $input->sources = $catalog->defaults();
        // The roster's "+ Nuevo" links arrive with the researcher's id prefilled.
        $input->query = $request->query->get('q');

        $form = $this->createForm(NewAnalysisType::class, $input);
        $form->handleRequest($request);
        $candidates = [];

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $resolved = $engine->resolve((string) $input->query);

                if ($resolved->isUnique()) {
                    return $this->launch($resolved, $input, $registry, $runner, $health, $catalog);
                }

                if ($resolved->candidates === []) {
                    $form->get('query')->addError(new FormError(
                        sprintf('OpenAlex no encontró a nadie con "%s".', $input->query)
                    ));
                } else {
                    // Ambiguous: fall through to the disambiguation list below.
                    $candidates = $resolved->candidates;
                }
            } catch (CitationEngineException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('analysis/new.html.twig', [
            'form' => $form,
            'candidates' => $candidates,
            'input' => $input,
            'engine_healthy' => $engine->isHealthy(),
            // Picking from the padrón is the normal way in; searching is the
            // exception, for someone who is not on it yet.
            'roster' => $researchers->findRoster(),
            'source_options' => $this->sourceOptions($catalog),
            'source_defaults' => $catalog->defaults(),
        ]);
    }

    /**
     * Second step of the ambiguous case: the user picked one of the candidates, so
     * resolve that exact id (authoritative name/works count) and queue it.
     */
    #[Route('/analisis/iniciar', name: 'app_analysis_start', methods: ['POST'])]
    public function start(
        Request $request,
        CitationEngineClient $engine,
        ResearcherRegistry $registry,
        AnalysisRunner $runner,
        ServiceHealthChecker $health,
        SourceCatalog $catalog,
    ): Response {
        // Field name must be `_csrf_token`: that is what the stateless-CSRF
        // JS looks for to swap in the real token and set the matching cookie.
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $this->redirectToRoute('app_analysis_new');
        }

        $input = new NewAnalysisInput();
        $input->query = (string) $request->request->get('author_id');
        $input->wantReport = $request->request->getBoolean('want_report');
        $input->reportLanguage = $request->request->get('report_language') === 'en' ? 'en' : 'es';
        $input->sources = $request->request->all('sources');

        try {
            $resolved = $engine->resolve($input->query);
        } catch (CitationEngineException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_analysis_new');
        }

        if (!$resolved->isUnique()) {
            $this->addFlash('error', sprintf('"%s" sigue siendo ambiguo; usa el ORCID o el ID de OpenAlex.', $input->query));

            return $this->redirectToRoute('app_analysis_new');
        }

        return $this->launch($resolved, $input, $registry, $runner, $health, $catalog);
    }

    /**
     * Runs are addressed by slug ("michael-hrusak-20260729-2354"), so the URL says
     * who and when. The requirement keeps the literal routes above from being
     * swallowed by this pattern regardless of declaration order.
     */
    #[Route(
        '/analisis/{slug}',
        name: 'app_run_show',
        requirements: ['slug' => '(?!nuevo$|iniciar$|padron$)[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function show(
        Request $request,
        string $slug,
        ArticleRepository $articles,
        AnalysisRunRepository $runs,
        AuditLogRepository $audit,
        StalledRunReaper $reaper,
        CitationEngineClient $engine,
    ): Response {
        // Looked up by hand rather than with MapEntity: the archive filter hides
        // archived runs from every ordinary lookup, and this page is where someone
        // goes to read one (and to put it back).
        $run = $runs->findBySlugIncludingArchived($slug) ?? throw $this->createNotFoundException();

        // This page is the one people stare at while waiting, so it is where an
        // abandoned run should stop pretending to be in progress. Throttled, since
        // the page reloads itself every 5 seconds.
        if (!$run->getStatus()->isTerminal()) {
            $reaper->sweepThrottled();
        }

        // The run's totals come from its own denormalized columns, not from the
        // articles on screen, so paging them changes no figure on this page.
        $articleSort = ArticleSort::from_($request->query->get('orden'));
        $articleSearch = trim((string) $request->query->get('buscar'));
        $articleCount = $run->getStatus()->hasFigures() ? $articles->countForRun($run, $articleSearch) : 0;
        $articlePages = max(1, (int) ceil($articleCount / self::ARTICLES_PER_PAGE));
        $articlePage = min(max(1, $request->query->getInt('art', 1)), $articlePages);
        $pageArticles = $articleCount > 0
            ? $articles->findPageForRun($run, $articlePage, self::ARTICLES_PER_PAGE, $articleSort, $articleSearch)
            : [];

        return $this->render('analysis/show.html.twig', [
            'run' => $run,
            // Empty while queued/running, and for failed runs.
            'articles' => $pageArticles,
            'citing_counts' => $articles->citingWorkCounts(array_map(
                static fn (Article $a): int => (int) $a->getId(),
                $pageArticles,
            )),
            'article_count' => $articleCount,
            'article_page' => $articlePage,
            'article_pages' => $articlePages,
            'article_sort' => $articleSort,
            'article_search' => $articleSearch,
            // The run's own total, so a search can say "12 de 165" rather than
            // silently redefining how many articles the analysis found.
            'article_total' => $run->getTotalArticles(),
            // What a run of this kind usually takes — the only honest thing we can
            // say about progress without the engine reporting its phase.
            'typical_seconds' => $run->getStatus()->isTerminal() ? null : $runs->medianDurationSeconds(),
            // Only while a worker is actually inside /analyze: a queued run has no
            // progress to report, and asking about a finished one is a wasted call.
            'progress' => $run->getStatus() === RunStatus::RUNNING
                ? $engine->progress((string) $run->getId())
                : null,
            // Who archived it, for the banner. Only looked up when there is a banner.
            'archived_by' => $run->isArchived()
                ? $audit->latestFor(AuditAction::RUN_ARCHIVED, (int) $run->getId())
                : null,
        ]);
    }

    /**
     * One article's citing works, as an HTML fragment.
     *
     * Fetched when someone opens that article rather than shipped with the page: a
     * run of a prolific author holds ~1,470 citations against 165 articles, and
     * almost nobody expands more than a couple. The same URL renders the list on
     * its own, so the page still works with JavaScript disabled.
     */
    #[Route(
        '/analisis/{slug}/articulo/{id}/citas',
        name: 'app_run_article_citations',
        requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function articleCitations(
        string $slug,
        int $id,
        AnalysisRunRepository $runs,
        ArticleRepository $articles,
    ): Response {
        $run = $runs->findBySlugIncludingArchived($slug) ?? throw $this->createNotFoundException();
        $article = $articles->find($id);

        // The article must belong to the run in the URL: without this check the id
        // alone would serve any run's citations under any other run's address.
        if ($article === null || $article->getAnalysisRun()->getId() !== $run->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('analysis/_citing_works.html.twig', ['article' => $article]);
    }

    /**
     * The run as a workbook, for checking it away from the screen.
     *
     * Validation is what this is for: the librarian goes down the citations sheet,
     * follows each DOI and records whether the A/B/autocita call is right. That is a
     * sorting-and-filtering job, which is why it is a spreadsheet and not the
     * narrative report — and why the sheet ships with empty columns to write in.
     *
     * Archived runs export too. Archiving hides a run from the aggregates, but the
     * reason they are kept at all is that figures already published from them have
     * to stay reproducible, and that is exactly when someone asks for the workbook.
     */
    #[Route(
        '/analisis/{slug}/exportar',
        name: 'app_run_export',
        requirements: ['slug' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function export(
        string $slug,
        AnalysisRunRepository $runs,
        ArticleRepository $articles,
        RunWorkbook $workbook,
    ): Response {
        $run = $runs->findBySlugIncludingArchived($slug) ?? throw $this->createNotFoundException();

        // Nothing to check in a run that produced no figures, and a workbook of empty
        // sheets is worse than the message saying so.
        if (!$run->getStatus()->hasFigures()) {
            $this->addFlash('error', 'Este análisis no tiene cifras que exportar.');

            return $this->redirectToRoute('app_run_show', ['slug' => $run->getSlug()]);
        }

        $book = $workbook->build($run, $articles->findAllForRunWithCitations($run));

        // Streamed: the writer emits the file as it goes, so a prolific author's run
        // (~1,470 citation rows) never has to exist twice, once built and once copied
        // into a response body.
        $response = new StreamedResponse(static function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            // The sheets hold every citation of the run; without this they stay in
            // memory for the rest of the request.
            $book->disconnectWorksheets();
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $workbook->filename($run),
        ));
        // The figures are fixed once the run finishes, but a stale copy of someone's
        // citation record is not worth the bandwidth it saves.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Write (or rewrite) the narrative report for a run that already has figures.
     * Queued, not inline: the model takes tens of seconds. Uses the run's stored
     * engine snapshot, so no OpenAlex calls and no risk of different numbers.
     */
    #[Route('/analisis/{slug}/informe', name: 'app_run_report', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function report(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])]
        AnalysisRun $run,
        AnalysisRunner $runner,
    ): Response {
        $redirect = $this->redirectToRoute('app_run_show', ['slug' => $run->getSlug()]);

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $redirect;
        }

        if (!$run->canGenerateReport()) {
            $this->addFlash('error', $run->isReportPending()
                ? 'Ya hay un informe en proceso para este análisis.'
                : 'Este análisis no tiene cifras (o resultado del motor) con las que redactar un informe.');

            return $redirect;
        }

        $language = $request->request->get('report_language') === 'en' ? 'en' : 'es';
        $runner->queueReport($run, $language);

        $this->addFlash('success', 'Informe en cola. Tarda cerca de un minuto; esta página se actualizará sola.');

        return $redirect;
    }

    /**
     * Stop a queued or running analysis.
     *
     * Allowed for whoever launched it and for administrators — a run stuck since
     * last week is usually cleared by someone other than the person who started it.
     * See AnalysisRunner::cancel() for what "stop" can actually guarantee.
     */
    #[Route('/analisis/{slug}/cancelar', name: 'app_run_cancel', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function cancel(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])]
        AnalysisRun $run,
        AnalysisRunner $runner,
    ): Response {
        $redirect = $this->redirectToRoute('app_run_show', ['slug' => $run->getSlug()]);

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $redirect;
        }

        $user = $this->getUser();
        $owner = $run->getOwner();

        if (!$this->isGranted('ROLE_ADMIN') && ($owner === null || $owner->getId() !== ($user instanceof User ? $user->getId() : null))) {
            $this->addFlash('error', 'Sólo quien lanzó el análisis, o un administrador, puede cancelarlo.');

            return $redirect;
        }

        if ($runner->cancel($run, $this->actor())) {
            $this->addFlash('success', 'Análisis cancelado.');
        } else {
            $this->addFlash('error', sprintf(
                'Este análisis ya está «%s»; no hay nada que cancelar.',
                $run->getStatus()->label(),
            ));
        }

        return $redirect;
    }

    /**
     * Hide a finished analysis, or put it back. Administrators only: what is in the
     * history is what the dashboard's figures are computed from.
     */
    #[Route('/analisis/{slug}/archivar', name: 'app_run_archive', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function archive(Request $request, string $slug, AnalysisRunRepository $runs, AnalysisRunner $runner): Response
    {
        $run = $runs->findBySlugIncludingArchived($slug) ?? throw $this->createNotFoundException();
        $redirect = $this->redirectToRoute('app_run_show', ['slug' => $run->getSlug()]);

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $redirect;
        }

        $by = $this->actor();
        $restoring = $request->request->getBoolean('restaurar');

        if ($restoring) {
            $this->addFlash(...$runner->restore($run, $by)
                ? ['success', 'Análisis restaurado: vuelve al historial y a las cifras.']
                : ['error', 'Este análisis no está archivado.']);

            return $redirect;
        }

        if ($runner->archive($run, $by)) {
            $this->addFlash('success', 'Análisis archivado: sale del historial y de las cifras, pero se conserva.');
        } else {
            $this->addFlash('error', $run->isArchived()
                ? 'Este análisis ya estaba archivado.'
                : 'Sólo se archivan análisis con cifras. Los que no produjeron nada se eliminan.');
        }

        return $redirect;
    }

    /**
     * Permanently delete a run that produced no figures. See AnalysisRunner::delete()
     * for why runs with figures are archived instead.
     */
    #[Route('/analisis/{slug}/eliminar', name: 'app_run_delete', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, string $slug, AnalysisRunRepository $runs, AnalysisRunner $runner): Response
    {
        $run = $runs->findBySlugIncludingArchived($slug) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $this->redirectToRoute('app_run_show', ['slug' => $run->getSlug()]);
        }

        $label = sprintf('#%d (%s)', $run->getId(), $run->getResearcher()->getDisplayName());

        if (!$runner->delete($run, $this->actor())) {
            $this->addFlash('error', 'Este análisis tiene cifras: archívalo en vez de borrarlo, o las cifras del panel cambiarían sin dejar rastro.');

            return $this->redirectToRoute('app_run_show', ['slug' => $run->getSlug()]);
        }

        $this->addFlash('success', sprintf('Análisis %s eliminado.', $label));

        // Its page no longer exists.
        return $this->redirectToRoute('app_run_index');
    }

    /**
     * The optional sources, each with why it cannot be picked when that applies —
     * offering a choice that would silently do nothing is worse than saying so.
     *
     * @return list<array{source: AnalysisSource, available: bool, reason: ?string}>
     */
    private function sourceOptions(SourceCatalog $catalog): array
    {
        return array_map(static fn (AnalysisSource $source): array => [
            'source' => $source,
            'available' => $catalog->isAvailable($source),
            'reason' => $catalog->unavailableReason($source),
        ], AnalysisSource::optional());
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * Launch for someone already in the padrón — the ordinary case, and the one
     * that should not require typing an identifier at all.
     *
     * No /resolve round-trip: the roster entry already holds the ORCID or OpenAlex
     * id, and execute() refreshes the author's details from the engine's answer
     * anyway. The one thing worth stopping for is a researcher with neither
     * identifier, where the engine would fall back to a name search and either
     * refuse or, worse, match somebody else.
     */
    #[Route('/analisis/padron', name: 'app_analysis_start_roster', methods: ['POST'])]
    public function startFromRoster(
        Request $request,
        ResearcherRepository $researchers,
        AnalysisRunner $runner,
        ServiceHealthChecker $health,
        SourceCatalog $catalog,
    ): Response {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $this->redirectToRoute('app_analysis_new');
        }

        $researcher = $researchers->find($request->request->getInt('researcher'));

        if ($researcher === null) {
            $this->addFlash('error', 'Elige un investigador de la lista.');

            return $this->redirectToRoute('app_analysis_new');
        }

        if ($researcher->preferredEngineQuery() === null) {
            $this->addFlash('error', sprintf(
                '%s no tiene ORCID ni ID de OpenAlex en su ficha, y sin uno de los dos no se puede saber con certeza de quién son los trabajos. Añádelo primero.',
                $researcher->getDisplayName(),
            ));

            return $this->redirectToRoute('app_researcher_show', ['slug' => $researcher->getSlug()]);
        }

        return $this->queueFor(
            $researcher,
            $request->request->getBoolean('want_report'),
            $request->request->get('report_language') === 'en' ? 'en' : 'es',
            $runner,
            $health,
            sources: $catalog->sanitize($request->request->all('sources')),
        );
    }

    /**
     * Roster entry + queued run + redirect to the detail page, which is where the
     * user watches the worker pick it up.
     */
    private function launch(
        ResolveResult $resolved,
        NewAnalysisInput $input,
        ResearcherRegistry $registry,
        AnalysisRunner $runner,
        ServiceHealthChecker $health,
        SourceCatalog $catalog,
    ): Response {
        $researcher = $registry->fromAuthor($resolved->author);

        return $this->queueFor(
            $researcher,
            $input->wantReport,
            $input->reportLanguage,
            $runner,
            $health,
            $input->query,
            $catalog->sanitize($input->sources),
        );
    }

    /**
     * Queue a run for a researcher already in the roster, checking the services
     * first. Shared by both ways in — picking someone from the padrón, and the
     * search that ends in a resolved author.
     */
    private function queueFor(
        Researcher $researcher,
        bool $wantReport,
        string $reportLanguage,
        AnalysisRunner $runner,
        ServiceHealthChecker $health,
        ?string $backTo = null,
        ?array $sources = null,
    ): Response {
        // Probed fresh, not from the panel's cached snapshot: the point is the state
        // of the services *now*, and a run costs minutes before it would notice.
        $preflight = PreflightCheck::from($health->check(fresh: true));

        if (!$preflight->canRun) {
            $this->addFlash('error', (string) $preflight->blocker);

            return $this->redirectToRoute('app_analysis_new', $backTo !== null ? ['q' => $backTo] : []);
        }

        $owner = $this->getUser();
        $run = $runner->queue(
            $researcher,
            owner: $owner instanceof User ? $owner : null,
            wantReport: $wantReport && $preflight->canReport,
            reportLanguage: $reportLanguage,
            sources: $sources,
        );

        if ($wantReport && !$preflight->canReport) {
            $this->addFlash('warning', (string) $preflight->reportBlocker);
        }

        $this->addFlash('success', sprintf(
            'Análisis #%d en cola para %s.',
            $run->getId(),
            $researcher->getDisplayName(),
        ));

        return $this->redirectToRoute('app_run_show', ['slug' => $run->getSlug()]);
    }
}
