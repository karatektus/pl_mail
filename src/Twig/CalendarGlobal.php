<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Calendar\HappeningSoonRow;
use App\Entity\User\User;
use App\Service\Calendar\HappeningSoonReader;
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

    private bool $soonestResolved = false;

    private ?HappeningSoonRow $soonest = null;

    public function __construct(
        private readonly UpcomingEventIndicator $indicator,
        private readonly HappeningSoonReader    $happeningSoon,
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

    /**
     * The next thing read out of mail that is coming up, or null.
     *
     * What the topbar's "Happening Soon" trigger is drawn from — it renders only
     * when this answers something, and it wears that thing's kind icon. An icon
     * rather than a second dot beside the calendar button's: a plane says both
     * "there is a reason to look" and what the reason is, in the same space, and
     * two dots in one corner of a topbar say neither.
     *
     * Its own memo rather than sharing getUpcoming()'s, deliberately: the two
     * answer different questions over different windows — the dot is anything
     * still ahead today, this is anything extracted within the fortnight — and
     * folding them into one lookup is how a change to either would silently
     * move the other.
     *
     * The full panel is NOT read here. It costs a page's worth of query and the
     * topbar renders on every authenticated page, so the list is fetched by the
     * route that draws it, when somebody actually opens it.
     */
    public function getSoonest(): ?HappeningSoonRow
    {
        if (true === $this->soonestResolved) {
            return $this->soonest;
        }

        $this->soonestResolved = true;
        $user                  = $this->security->getUser();

        if (true === $user instanceof User) {
            $this->soonest = $this->happeningSoon->next($user);
        }

        return $this->soonest;
    }
}
