<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Remembers which admin panels a user has collapsed.
 *
 * Server-side rather than localStorage (which is what the sidebar rail uses)
 * so the layout follows the user to another browser or machine — and because
 * the panels are rendered server-side, so the state has to be known before the
 * first paint or every load would flash them all open.
 *
 * Stored on User::$settings, the same free-form jsonb bag Account::$settings
 * has always been.
 */
#[Route('/admin/panel', name: 'app_admin_panel_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPanelController extends AbstractController
{
    /**
     * Every collapsible panel. A whitelist, so a stray client cannot grow the
     * settings blob without bound.
     */
    private const array PANELS = [
        'processes',
        'maintenance',
        'webhooks',
        'tokens',
        'queues',
        'failed',
        'accounts',
        'tables',
        'db-gauges',
        'db-slow',
        'db-heavy',
        'db-active',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $key     = (string) ($payload['key'] ?? '');

        if (false === in_array($key, self::PANELS, true)) {
            return $this->json(['ok' => false, 'error' => 'unknown panel'], Response::HTTP_BAD_REQUEST);
        }

        if (false === $this->isCsrfTokenValid('admin_panel_' . $key, (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();

        $user->setAdminPanelCollapsed($key, true === ($payload['collapsed'] ?? false));
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }
}
