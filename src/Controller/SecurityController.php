<?php

namespace App\Controller;

use App\Repository\AnalysisRunRepository;
use App\Repository\ResearcherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login and logout. There is no self-registration: accounts are created by an
 * administrator with `app:user:create`, because SIAB's users are CCM staff.
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(
        AuthenticationUtils $authenticationUtils,
        ResearcherRepository $researchers,
        AnalysisRunRepository $runs,
    ): Response {
        // Already signed in? The login form has nothing to offer.
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_email' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            // Volume figures for the hero panel. Counts only — nothing here names a
            // researcher or states anyone's Type A share, which is what the login
            // being the one public page rules out.
            'total_researchers' => $researchers->countAll(),
            'total_runs' => $runs->countAll(),
            'total_citations' => $runs->sumCitationsAnalyzed(),
        ]);
    }

    /** Intercepted by the firewall's logout listener; the body never runs. */
    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function logout(): never
    {
        throw new \LogicException('El firewall intercepta esta ruta.');
    }
}
