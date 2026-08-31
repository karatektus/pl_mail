<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Jmap\Method\Calendar\CalendarEventChangesMethod;
use App\Jmap\Protocol\Exception\MethodException;

/**
 * CalendarEvent/changes answers a real delta, and refuses rather than guesses.
 *
 * The method exists because calendar_change_log does; before it, every calendar
 * method returned the string "fixed" and CalendarState explained that a token
 * nobody could trust was worse than none. So the claims worth pinning are the
 * ones that make a token trustworthy: that a delta is complete between two
 * states, that asking again with the new state is empty, and — the half that
 * protects clients — that a token this server cannot place is refused outright
 * instead of being answered with "nothing changed", which would leave that
 * client silently stale forever.
 */
final class CalendarEventChangesMethodTest extends CalendarMethodTestCase
{
    private CalendarEventChangesMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(CalendarEventChangesMethod::class);
    }

    public function testAFreshClientIsToldAboutEverythingItHasNotSeen(): void
    {
        $calendar = $this->seedCalendar('Work');
        $event    = $this->seedEvent($calendar, 'Kickoff', $this->baseDay());

        $result = $this->changes('0');

        self::assertSame([(string) $event->id], $result['created']);
        self::assertSame([], $result['updated']);
        self::assertSame([], $result['destroyed']);
        self::assertFalse($result['hasMoreChanges']);
    }

    /** A token is only worth anything if using it settles. */
    public function testAskingAgainWithTheNewStateReportsNothing(): void
    {
        $calendar = $this->seedCalendar('Work');
        $this->seedEvent($calendar, 'Kickoff', $this->baseDay());

        $first  = $this->changes('0');
        $second = $this->changes($first['newState']);

        self::assertSame([], $second['created']);
        self::assertSame([], $second['updated']);
        self::assertSame([], $second['destroyed']);
        self::assertSame($first['newState'], $second['newState'], 'a settled state must not drift');
    }

    public function testAnEditArrivesAsUpdatedRatherThanCreated(): void
    {
        $calendar = $this->seedCalendar('Work');
        $event    = $this->seedEvent($calendar, 'Kickoff', $this->baseDay());

        $since = $this->changes('0')['newState'];

        $event->title = 'Kickoff (moved)';
        $this->em->flush();

        $result = $this->changes($since);

        self::assertSame([], $result['created']);
        self::assertSame([(string) $event->id], $result['updated']);
    }

    public function testADeletionArrivesAsDestroyed(): void
    {
        $calendar = $this->seedCalendar('Work');
        $event    = $this->seedEvent($calendar, 'Doomed', $this->baseDay());
        $id       = (string) $event->id;

        $since = $this->changes('0')['newState'];

        $this->em->remove($event);
        $this->em->flush();

        self::assertSame([$id], $this->changes($since)['destroyed']);
    }

    /**
     * A client that never saw the event has nothing to forget, and a
     * destruction for an id it does not hold is noise.
     */
    public function testAnEventCreatedAndDeletedInsideOneWindowIsNotReportedAtAll(): void
    {
        $calendar = $this->seedCalendar('Work');

        $since = $this->changes('0')['newState'];

        $event = $this->seedEvent($calendar, 'Brief life', $this->baseDay());
        $this->em->remove($event);
        $this->em->flush();

        $result = $this->changes($since);

        self::assertSame([], $result['created']);
        self::assertSame([], $result['updated']);
        self::assertSame([], $result['destroyed']);
    }

    /**
     * The protective half. Answering "nothing changed" to a token we cannot
     * place would leave the client stale with no way to notice.
     */
    public function testAnUnreadableTokenAsksTheClientToStartOverRatherThanAnsweringNothing(): void
    {
        $this->expectException(MethodException::class);
        $this->expectExceptionMessage('Unrecognised state token.');

        $this->changes('not-a-sequence');
    }

    public function testATokenAheadOfTheLogIsRefused(): void
    {
        $calendar = $this->seedCalendar('Work');
        $this->seedEvent($calendar, 'Kickoff', $this->baseDay());

        $ahead = (string) (((int) $this->changes('0')['newState']) + 1000);

        $this->expectException(MethodException::class);

        $this->changes($ahead);
    }

    /**
     * @return array<string,mixed>
     */
    private function changes(string $sinceState): array
    {
        return $this->method->handle(
            ['accountId' => $this->accountId(), 'sinceState' => $sinceState],
            $this->context(),
        );
    }
}
