<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Repository\User\UserRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Whether this install still needs its first user.
 *
 * One predicate, in one place, because it is the whole security story of the
 * /install page: that page creates an administrator without asking anyone for
 * credentials, which is safe exactly as long as there is nobody to ask. The
 * moment a user exists it must be as if the route had never been routed.
 */
final readonly class InstallGuard
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function isAvailable(): bool
    {
        return 0 === $this->users->countAll();
    }

    /**
     * 404 rather than a redirect to the login page: a redirect confirms the
     * endpoint exists and is merely closed, and there is nothing to gain from
     * telling anyone that.
     */
    public function assertAvailable(): void
    {
        if (false === $this->isAvailable()) {
            throw new NotFoundHttpException();
        }
    }
}
