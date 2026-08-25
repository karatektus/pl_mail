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
 * moment a real user exists it must be as if the route had never been routed.
 *
 * "Real" is load-bearing, and was learned the hard way when demo mode shipped:
 * a demo mints a throwaway user per visitor, so on a fresh demo instance the
 * first passer-by — or crawler — closed this page permanently, before the
 * operator had made themselves an administrator. Recovery meant app:setup on
 * the console, and nothing on the page said why it had vanished.
 */
final readonly class InstallGuard
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function isAvailable(): bool
    {
        // Demo visitors do not count. Demo mode mints a user per arrival, so
        // counting them would let the first stranger through the door take the
        // install page away with them — see
        // UserRepository::countExcludingDemoVisitors(). On an install that is
        // not a demo the two counts are identical, because nothing can create
        // an address in that shape.
        return 0 === $this->users->countExcludingDemoVisitors();
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
