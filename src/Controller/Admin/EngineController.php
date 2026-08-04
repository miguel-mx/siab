<?php

namespace App\Controller\Admin;

use App\Engine\EngineLauncher;
use App\Entity\User;
use App\Health\ServiceHealthChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Operating the Python engine from the browser.
 *
 * The button exists because the people who launch analyses cannot open a terminal
 * on this server: until now a stopped engine meant asking someone else to run
 * uvicorn. Starting is all it does — no stop, no restart — so a mistaken click can
 * never take the engine away from a run in progress.
 */
#[Route('/admin/motor')]
#[IsGranted('ROLE_ADMIN')]
final class EngineController extends AbstractController
{
    /**
     * Where the button may send the administrator back to. A route name from a
     * fixed list, not a URL from the request, so this cannot become an open
     * redirect no matter what is posted.
     */
    private const RETURN_ROUTES = ['app_dashboard', 'app_analysis_new', 'app_admin_settings'];

    #[Route('/arrancar', name: 'app_admin_engine_start', methods: ['POST'])]
    public function start(Request $request, EngineLauncher $launcher, ServiceHealthChecker $health): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $this->redirectToRoute('app_admin_settings');
        }

        $actor = $this->getUser();
        $outcome = $launcher->start($actor instanceof User ? $actor->getEmail() : null);

        // The panel caches its probe for half a minute; after starting the engine
        // that snapshot is exactly the one nobody wants to look at.
        $health->check(fresh: true);

        if (!$outcome->ok) {
            $this->addFlash('error', $outcome->message.' Revisa el registro del motor más abajo.');

            // Failures land on the admin screen because that is where the log tail
            // and the configured command are shown.
            return $this->redirectToRoute('app_admin_settings');
        }

        $this->addFlash('success', $outcome->message);

        $back = (string) $request->request->get('volver');

        return $this->redirectToRoute(in_array($back, self::RETURN_ROUTES, true) ? $back : 'app_admin_settings');
    }
}
