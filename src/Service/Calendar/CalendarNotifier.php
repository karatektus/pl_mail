<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells an open page that its calendar moved.
 *
 * Carries no state beyond "look again", like RuleRunNotifier: extraction can
 * create, update or cancel several events across several calendars in one job,
 * and describing that in a payload would be a second, subtly different
 * implementation of what the page already knows how to render.
 *
 * Every publish is guarded. A hub that is down must never fail the extraction
 * that has already succeeded — the events are in the database either way, and
 * the page will catch up on its next navigation.
 */
final readonly class CalendarNotifier
{
    public function __construct(
        private HubInterface    $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publishCalendarChanged(User $user): void
    {
        $id = $user->id;

        if (null === $id) {
            return;
        }

        $this->publish($id, ['type' => 'calendar.changed']);
    }

    /**
     * One calendar's sync run has finished, and whether it worked.
     *
     * Unlike publishCalendarChanged this one is NOT a "look again" hint, and
     * that is why it carries a payload where the other refuses to. Its listener
     * is the account-health card, which asked for this sync by hand and has
     * been saying "started" ever since; to stop saying that it needs to know
     * which calendar and which outcome, and there is no second place it could
     * read either from — the run happened in a worker, and the page has no
     * request in flight to answer it.
     *
     * Published on BOTH paths on purpose. A success alone would leave a repeat
     * failure indistinguishable from a worker that never ran, and the card would
     * sit claiming to be waiting on something that had already come back and
     * said no. The failure is the more important of the two messages: it is the
     * one that means the repair did not work.
     *
     * Guarded like everything else here — the sync itself has already been
     * recorded, and a hub that is down must not turn a completed run into a
     * failed envelope. The cost of a lost publish is a card that stays on
     * "started" until the page is loaded again, which is the same place the
     * fallback in the browser lands.
     */
    public function publishCalendarSyncFinished(Calendar $calendar, bool $ok): void
    {
        $userId     = $calendar->usr?->id;
        $calendarId = $calendar->id;

        if (null === $userId || null === $calendarId) {
            return;
        }

        $this->publish($userId, [
            'type'       => 'calendar.sync-finished',
            'calendarId' => $calendarId,
            'ok'         => $ok,
            // The stored message, so a card reporting a REPEAT failure can show
            // what it failed with this time rather than re-showing the error the
            // user just pressed a button about. Already truncated to 500 by
            // Calendar::recordSyncFailure(), and it is the same string the page
            // renders behind the disclosure on a full load.
            'error'      => false === $ok ? $calendar->lastSyncError : null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publish(int $userId, array $data): void
    {
        try {
            $this->hub->publish(new Update(
                topics: [sprintf('mail/user/%d', $userId)],
                data: json_encode($data, JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('CalendarNotifier: publish failed', [
                'userId' => $userId,
                'type'   => $data['type'] ?? null,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
