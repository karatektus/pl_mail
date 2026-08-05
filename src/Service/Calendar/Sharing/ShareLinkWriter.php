<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sharing;

use App\Domain\Enum\Calendar\ShareDetail;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarShareLink;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place a share link is created, edited, re-tokened or revoked.
 *
 * A writer for the reason CalendarEventWriter is one: several things about a
 * link have to be true together and no caller should be trusted to remember all
 * of them. The window columns have to agree with the window mode, the revealed
 * set has to be ShareDetail cases rather than whatever the form posted, and the
 * calendars have to belong to the person doing the sharing — which is the check
 * that matters, because the form posts calendar ids and a crafted post naming
 * somebody else's calendar is the difference between this feature and a
 * vulnerability.
 *
 * **Ownership is re-resolved, never trusted.** The ids are looked up through
 * CalendarRepository::findOneForUser, so an id that is not the user's resolves
 * to nothing and is silently dropped rather than refused. Dropped rather than
 * refused deliberately: refusing would confirm that the id exists, and a link
 * covering nothing at all renders as "this link shows no calendars", which is a
 * state the owner can see and correct.
 *
 * **Editing does not re-token.** That is the second decision worth arguing.
 * Changing what a link reveals must not break the URL somebody already has —
 * the user's words were that the boxes must be "editable afterwards", and a URL
 * that died on every edit would make narrowing a link impossible without
 * re-sending it. Widening one silently is the risk that buys, and it is
 * accepted because the owner is the only person who can do it and the list
 * shows what each link reveals. regenerate() exists for the other case: a URL
 * that leaked, where a new token and a dead old one is the whole point.
 *
 * Does not flush; it joins the caller's unit of work, like every other writer
 * here.
 */
final readonly class ShareLinkWriter
{
    public function __construct(
        private CalendarRepository     $calendars,
        private PublicLinkToken        $tokens,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * A new link, and the only moment its token is readable.
     *
     * Returns the token rather than putting it on the entity, so there is no
     * property anybody can be tempted to persist and no object carrying a live
     * credential past the response that minted it.
     *
     * @param array<mixed> $detailValues as posted, so entirely untrusted
     * @param array<mixed> $calendarIds  as posted, so entirely untrusted
     */
    public function create(
        User               $user,
        string             $name,
        array              $detailValues,
        array              $calendarIds,
        ShareWindow        $window,
        int                $rollingDays,
        ?DateTimeImmutable $startsOn,
        ?DateTimeImmutable $endsOn,
    ): string {
        $link      = new CalendarShareLink();
        $link->usr = $user;

        $token = $this->tokens->mint();

        $link->tokenDigest = $this->tokens->digest($token);

        $this->apply($link, $user, $name, $detailValues, $calendarIds, $window, $rollingDays, $startsOn, $endsOn);

        $this->em->persist($link);

        return $token;
    }

    /**
     * Everything about an existing link except its token.
     *
     * @param array<mixed> $detailValues as posted, so entirely untrusted
     * @param array<mixed> $calendarIds  as posted, so entirely untrusted
     */
    public function update(
        CalendarShareLink  $link,
        User               $user,
        string             $name,
        array              $detailValues,
        array              $calendarIds,
        ShareWindow        $window,
        int                $rollingDays,
        ?DateTimeImmutable $startsOn,
        ?DateTimeImmutable $endsOn,
    ): void {
        $this->apply($link, $user, $name, $detailValues, $calendarIds, $window, $rollingDays, $startsOn, $endsOn);
    }

    /**
     * A new token for the same link, and the old URL stops working at once.
     *
     * The recovery for a URL that went somewhere it should not have, and the
     * only way to see a link's address again after the response that created it
     * — which is the cost of not storing the token, stated in
     * CalendarShareLink's docblock.
     *
     * Un-revokes as a side effect, and that is intentional rather than
     * incidental: a revoked link whose token is regenerated is a link the owner
     * has decided to bring back, and leaving it revoked would mint a credential
     * that answers nothing.
     */
    public function regenerate(CalendarShareLink $link): string
    {
        $token = $this->tokens->mint();

        $link->tokenDigest = $this->tokens->digest($token);
        $link->revokedAt   = null;

        return $token;
    }

    /**
     * Switch a link off without losing it.
     *
     * Idempotent on purpose — a second revoke keeps the first one's timestamp,
     * because when it was switched off is a fact and a double-click is not a
     * new one.
     */
    public function revoke(CalendarShareLink $link): void
    {
        if (true === $link->isLive()) {
            $link->revokedAt = new DateTimeImmutable();
        }
    }

    /**
     * Record that somebody opened it.
     *
     * Separate from read() so the public GET decides whether a view counts —
     * the .ics endpoint is a subscribed client polling on a timer, and letting
     * that write would turn "last opened" into "the calendar app is still
     * subscribed", which is a different fact and not the one the owner is
     * looking for.
     *
     * Written on the entity and flushed by the caller. A failed write here must
     * never fail the page: see the controller, which does not treat this as
     * part of answering the request.
     */
    public function noteView(CalendarShareLink $link, DateTimeImmutable $at): void
    {
        $link->lastViewedAt = $at;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<mixed> $detailValues
     * @param array<mixed> $calendarIds
     */
    private function apply(
        CalendarShareLink  $link,
        User               $user,
        string             $name,
        array              $detailValues,
        array              $calendarIds,
        ShareWindow        $window,
        int                $rollingDays,
        ?DateTimeImmutable $startsOn,
        ?DateTimeImmutable $endsOn,
    ): void {
        $trimmed = trim($name);

        // A link with no name is a row the owner cannot tell from the others in
        // their own list, which is the list they revoke from. Named rather than
        // refused, because the name is bookkeeping and refusing a save over it
        // would lose the ticks the user actually came to set.
        $link->name = '' === $trimmed ? 'Shared calendar' : mb_substr($trimmed, 0, 120);

        $link->reveal(ShareDetail::fromList($detailValues));
        $link->cover($this->ownedCalendars($user, $calendarIds));

        $link->windowMode  = $window;
        $link->rollingDays = max(1, min(CalendarShareLink::MAX_ROLLING_DAYS, $rollingDays));

        // Both columns are written on every save whichever mode was chosen, so
        // switching a link from fixed back to rolling and forward again does
        // not resurrect the dates it had two edits ago. The reader clamps and
        // falls back anyway; this is what stops the row itself carrying a
        // window nobody chose.
        $link->startsOn = ShareWindow::Fixed === $window ? $startsOn : null;
        $link->endsOn   = ShareWindow::Fixed === $window ? $endsOn : null;
    }

    /**
     * The posted ids, resolved to calendars this user actually owns.
     *
     * @param array<mixed> $calendarIds
     *
     * @return list<Calendar>
     */
    private function ownedCalendars(User $user, array $calendarIds): array
    {
        $calendars = [];

        foreach ($calendarIds as $calendarId) {
            if (false === is_scalar($calendarId)) {
                continue;
            }

            $calendar = $this->calendars->findOneForUser($user, (int) $calendarId);

            if (null !== $calendar) {
                $calendars[(int) $calendar->id] = $calendar;
            }
        }

        return array_values($calendars);
    }
}
