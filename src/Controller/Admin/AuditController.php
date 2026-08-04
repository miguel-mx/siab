<?php

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The audit trail. Read-only by design — there is no route that writes or edits
 * an entry, because a log someone can tidy up answers nothing.
 */
#[Route('/admin/registro')]
#[IsGranted('ROLE_ADMIN')]
final class AuditController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('', name: 'app_admin_audit', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $entries): Response
    {
        $type = $request->query->get('tipo');
        $type = in_array($type, ['run', 'user'], true) ? $type : null;

        $total = $entries->countPage($type);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('p', 1)), $pages);

        return $this->render('admin/audit.html.twig', [
            'entries' => $entries->findPage($type, $page, self::PER_PAGE),
            'type' => $type,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
