<?php

namespace App\Controller;

use App\Dashboard\Sparkline;
use App\Health\ServiceHealthChecker;
use App\Repository\AnalysisRunRepository;
use App\Repository\ResearcherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The panel: KPIs, recent analyses, service health and the researcher roster.
 */
final class DashboardController extends AbstractController
{
    private const RECENT_RUNS = 6;
    private const ROSTER_ROWS = 8;
    private const SPARKLINE_MONTHS = 8;

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        ResearcherRepository $researchers,
        AnalysisRunRepository $runs,
        ServiceHealthChecker $health,
    ): Response {
        $monthStart = new \DateTimeImmutable('first day of this month midnight');
        $byMonth = $runs->countsByMonth(self::SPARKLINE_MONTHS);

        return $this->render('dashboard/index.html.twig', [
            'kpi' => [
                'researchers' => $researchers->countAll(),
                'researchers_with_runs' => $researchers->countWithAnalyses(),
                'runs' => $runs->countAll(),
                'runs_this_month' => $runs->countSince($monthStart),
                'type_a_share' => $runs->typeAShare(),
            ],
            'sparkline' => Sparkline::fromSeries($byMonth),
            'recent' => $runs->findRecent(self::RECENT_RUNS),
            'roster' => $researchers->findRosterWithStats(self::ROSTER_ROWS),
            'roster_total' => $researchers->countAll(),
            // "Revisar ahora" re-probes instead of reading the cached snapshot.
            'health' => $health->check(fresh: $request->query->getBoolean('revisar')),
        ]);
    }
}
