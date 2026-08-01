<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\Calendar\UpcomingEventIndicator;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What the topbar's calendar button needs to know, for a partial that cannot be
 * handed it.
 *
 * The topbar renders on every authenticated page from a layout, so there is no
 * controller in the middle to thread a variable through — the same reason
 * IntegrationsGlobal exists.
 *
 * Per-request memoised, including the null: the button renders once, but a
 * global that queried on every call would be a query per include the moment
 * something else wanted this.
 */
class CalendarGlobal
{
    private bool $resolved = false;

    /** @var array{state: string, startsAt: DateTimeImmutable, title: string}|null */
    private ?array $upcoming = null;

    public function __construct(
        private readonly UpcomingEventIndicator $indicator,
        private readonly Security               $security,
    ) {
    }

    /**
     * The next thing still to come today, or null.
     *
     * @return array{state: string, startsAt: DateTimeImmutable, title: string}|null
     */
    public function getUpcoming(): ?array
    {
        if (true === $this->resolved) {
            return $this->upcoming;
        }

        $this->resolved = true;
        $user           = $this->security->getUser();

        if (true === $user instanceof User) {
            $this->upcoming = $this->indicator->forUser($user);
        }

        return $this->upcoming;
    }
}
