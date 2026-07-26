<?php

declare(strict_types=1);

namespace App\Jmap\Controller;

use App\Entity\User;
use App\Jmap\Session\SessionBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Session discovery. Clients fetch /.well-known/jmap first (RFC 8620 §2.2),
 * then use the apiUrl from the returned Session object for all subsequent calls.
 */
final class JmapSessionController extends AbstractController
{
    public function __construct(
        private readonly SessionBuilder $sessionBuilder,
    ) {
    }

    #[Route('/.well-known/jmap', name: 'jmap_well_known', methods: ['GET'])]
    #[Route('/jmap/session', name: 'jmap_session', methods: ['GET'])]
    public function session(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->sessionBuilder->build($user));
    }
}
