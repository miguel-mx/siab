<?php

namespace App\Controller;

use App\Engine\CitationEngineClient;
use App\Engine\CitationEngineException;
use App\Engine\Dto\ResolveResult;
use App\Entity\Researcher;
use App\Repository\AnalysisRunRepository;
use App\Repository\ResearcherRepository;
use App\Service\ResearcherRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The roster (padrón) and one researcher's page: identifiers, current figures and
 * the full run history — the view an evaluation report is assembled from.
 */
final class ResearcherController extends AbstractController
{
    private const SLUG = '[a-z0-9-]+';

    #[Route('/investigadores', name: 'app_researcher_index', methods: ['GET'])]
    public function index(Request $request, ResearcherRepository $researchers): Response
    {
        $search = trim((string) $request->query->get('q'));

        return $this->render('researcher/index.html.twig', [
            'roster' => $researchers->findRosterWithStats(search: $search),
            'search' => $search,
            'total' => $researchers->countAll(),
        ]);
    }

    /**
     * Add someone to the padrón without analysing them yet.
     *
     * Until now the only way onto the roster was as a side effect of launching an
     * analysis, which is the wrong order for building the padrón: the librarian
     * knows who belongs on it long before anyone asks for their citation figures.
     *
     * The entry is still created from what OpenAlex resolves rather than from typed
     * text — a roster row whose identifiers nobody verified is exactly what makes a
     * later analysis attribute someone else's work.
     */
    #[Route('/investigadores/nuevo', name: 'app_researcher_new', methods: ['GET', 'POST'], priority: 1)]
    public function new(
        Request $request,
        CitationEngineClient $engine,
        ResearcherRegistry $registry,
        ResearcherRepository $researchers,
    ): Response {
        // `author_id` comes from the disambiguation list, `query` from the search
        // box; both mean "resolve this and add whoever it is".
        $query = trim((string) ($request->request->get('author_id') ?? $request->request->get('query') ?? ''));
        $candidates = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

                return $this->redirectToRoute('app_researcher_new');
            }

            if ($query === '') {
                $this->addFlash('error', 'Escribe un ORCID, un ID de OpenAlex o un nombre.');

                return $this->redirectToRoute('app_researcher_new');
            }

            try {
                $resolved = $engine->resolve($query);

                if ($resolved->isUnique()) {
                    return $this->addResolved($resolved, $registry, $researchers);
                }

                if ($resolved->candidates === []) {
                    $this->addFlash('error', sprintf('OpenAlex no encontró a nadie con "%s".', $query));
                } else {
                    $candidates = $resolved->candidates;
                }
            } catch (CitationEngineException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('researcher/new.html.twig', [
            'query' => $query,
            'candidates' => $candidates,
            'engine_healthy' => $engine->isHealthy(),
        ]);
    }

    /** Create the roster entry, or send them to the one that already exists. */
    private function addResolved(
        ResolveResult $resolved,
        ResearcherRegistry $registry,
        ResearcherRepository $researchers,
    ): Response {
        $author = $resolved->author;
        $openalexId = Researcher::normalizeOpenalexId($author->openalexId);
        $orcid = Researcher::normalizeOrcid($author->orcid);

        // Checked before creating, so "ya estaba" can be said plainly instead of the
        // registry quietly handing back the existing row as if it were new.
        $existing = ($openalexId !== null ? $researchers->findOneByOpenalexId($openalexId) : null)
            ?? ($orcid !== null ? $researchers->findOneByOrcid($orcid) : null);

        $researcher = $registry->fromAuthor($author);

        $this->addFlash('success', $existing !== null
            ? sprintf('%s ya estaba en el padrón.', $researcher->getDisplayName())
            : sprintf('%s añadido al padrón.', $researcher->getDisplayName()));

        return $this->redirectToRoute('app_researcher_show', ['slug' => $researcher->getSlug()]);
    }

    #[Route('/investigadores/{slug}', name: 'app_researcher_show', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Researcher $researcher,
        AnalysisRunRepository $runs,
    ): Response {
        $history = $runs->findForResearcher($researcher);

        return $this->render('researcher/show.html.twig', [
            'researcher' => $researcher,
            'history' => $history,
            // The figures that count right now: the newest run that produced any.
            'current' => $runs->findLatestCompletedForResearcher($researcher),
        ]);
    }

    /**
     * The manually-curated part of a researcher's record: the research area (which
     * OpenAlex does not provide), the ORCID and the per-source author identifiers.
     *
     * The identifiers matter more than they look. A Scopus AU-ID or an INSPIRE recid
     * pins exactly whose profile each source is queried for; a wrong one silently
     * folds another person's publications and citations into this researcher's
     * figures, with no error anywhere. Blank is always the safe value — the engine
     * then falls back to ORCID, or skips the source.
     */
    #[Route('/investigadores/{slug}/area', name: 'app_researcher_field', requirements: ['slug' => self::SLUG], methods: ['POST'])]
    public function updateField(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Researcher $researcher,
        EntityManagerInterface $em,
        ResearcherRepository $researchers,
    ): Response {
        $redirect = $this->redirectToRoute('app_researcher_show', ['slug' => $researcher->getSlug()]);

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $redirect;
        }

        $trimmed = static function (Request $r, string $key, int $max): ?string {
            $value = trim((string) $r->request->get($key));

            return $value !== '' ? mb_substr($value, 0, $max) : null;
        };

        // OpenAlex fills the ORCID when it knows one, which is often not the case
        // for people who never linked their record — hence editable here.
        $orcid = Researcher::normalizeOrcid((string) $request->request->get('orcid'));

        if ($orcid !== null && !Researcher::isValidOrcid($orcid)) {
            $this->addFlash('error', 'El ORCID no es válido. Se escribe 0000-0002-1825-0097; el último carácter es de control.');

            return $redirect;
        }

        // The column is unique, so without this the second entry would die on a
        // constraint violation instead of saying whose record already has it.
        if ($orcid !== null) {
            $owner = $researchers->findOneByOrcid($orcid);

            if ($owner !== null && $owner->getId() !== $researcher->getId()) {
                $this->addFlash('error', sprintf('Ese ORCID ya está en la ficha de %s.', $owner->getDisplayName()));

                return $redirect;
            }
        }

        $scopusId = $trimmed($request, 'scopus_id', 32);
        if ($scopusId !== null && !ctype_digit($scopusId)) {
            $this->addFlash('error', 'El AU-ID de Scopus es numérico (p. ej. 6602738988).');

            return $redirect;
        }

        $inspireRecid = $trimmed($request, 'inspire_recid', 32);
        if ($inspireRecid !== null && !ctype_digit($inspireRecid)) {
            $this->addFlash('error', 'El recid de INSPIRE-HEP es numérico.');

            return $redirect;
        }

        $researcher
            ->setField($trimmed($request, 'field', 255))
            ->setOrcid($orcid)
            ->setScopusId($scopusId)
            ->setZbmathCode($trimmed($request, 'zbmath_code', 64))
            ->setInspireRecid($inspireRecid)
            ->touch();

        $em->flush();

        $this->addFlash('success', 'Ficha actualizada. Se aplica al próximo análisis.');

        return $redirect;
    }
}
