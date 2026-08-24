<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Service\Calendar\CalendarNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * The message that lets the health card stop guessing.
 *
 * ── Why this is worth its own test ───────────────────────────────────────────
 * "Try syncing now" DISPATCHES a message and redirects. Nothing in the response
 * knows whether the sync worked, so the card can only say the sync was started
 * — and it goes on saying that until something tells it otherwise. This update
 * is that something, and it is the only one: there is no polling endpoint for a
 * calendar's sync state and no second request in flight to answer.
 *
 * So the three things asserted here are the three the card cannot work around
 * if they are wrong. It has to arrive on the topic the page subscribes to, it
 * has to name WHICH calendar (a user with three broken calendars would
 * otherwise clear the wrong card), and it has to be published on the FAILURE
 * path as well — a success-only message leaves a repeat failure looking
 * identical to a worker that never ran, and the card would sit claiming to be
 * waiting for an answer that had already come back and said no.
 */
final class CalendarSyncFinishedNotificationTest extends TestCase
{
    public function testASuccessfulRunNamesTheCalendarOnTheUsersTopic(): void
    {
        $hub      = new RecordingHub();
        $notifier = new CalendarNotifier($hub, new NullLogger());

        $notifier->publishCalendarSyncFinished($this->calendar(), true);

        self::assertCount(1, $hub->updates);

        $update = $hub->updates[0];

        self::assertSame(
            ['mail/user/7'],
            $update->getTopics(),
            'the page subscribes to mail/user/{id} and nothing else',
        );

        $data = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('calendar.sync-finished', $data['type']);
        self::assertSame(42, $data['calendarId'], 'the card is addressed by id, not by position');
        self::assertTrue($data['ok']);
        self::assertNull($data['error'], 'nothing failed, so there is nothing to report');
    }

    /**
     * The important half. A card that only ever hears about successes cannot
     * tell "it failed again" from "nobody is running the workers", and both
     * would render as an indefinite wait.
     */
    public function testAFailedRunSaysSoAndCarriesTheReason(): void
    {
        $hub      = new RecordingHub();
        $notifier = new CalendarNotifier($hub, new NullLogger());

        $calendar                = $this->calendar();
        $calendar->lastSyncError = 'The calendar no longer exists at the remote.';

        $notifier->publishCalendarSyncFinished($calendar, false);

        $data = json_decode($hub->updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($data['ok']);
        self::assertSame(
            'The calendar no longer exists at the remote.',
            $data['error'],
            'a repeat failure explains itself with THIS failure, not the one before it',
        );
    }

    /**
     * A calendar with no owner cannot be addressed to anybody, and publishing a
     * topic built from a null id would be publishing `mail/user/` — a topic
     * nothing subscribes to at best, and a cross-user leak at worst if the
     * pattern ever grew a wildcard.
     */
    public function testACalendarWithNoOwnerPublishesNothing(): void
    {
        $hub      = new RecordingHub();
        $notifier = new CalendarNotifier($hub, new NullLogger());

        $calendar      = $this->calendar();
        $calendar->usr = null;

        $notifier->publishCalendarSyncFinished($calendar, true);

        self::assertSame([], $hub->updates);
    }

    /**
     * The sync has already happened and already been recorded by the time this
     * is called. A hub that is down must not turn a completed run into a failed
     * envelope — the cost of a lost publish is a card that waits until the page
     * is loaded again, which is where the browser-side timeout lands anyway.
     */
    public function testAHubThatThrowsDoesNotTakeTheSyncDownWithIt(): void
    {
        $notifier = new CalendarNotifier(new ThrowingHub(), new NullLogger());

        $notifier->publishCalendarSyncFinished($this->calendar(), true);

        self::expectNotToPerformAssertions();
    }

    /**
     * Ids assigned by reflection, the way ApplyImapFlagsHandlerTest does it.
     * Both are `private(set)` — Doctrine writes them — and the whole point of
     * this test is what goes INTO the payload, which is those two ids.
     */
    private function calendar(): Calendar
    {
        $user = new User();
        new ReflectionProperty(User::class, 'id')->setValue($user, 7);

        $calendar = new Calendar();
        new ReflectionProperty(Calendar::class, 'id')->setValue($calendar, 42);
        $calendar->usr = $user;

        return $calendar;
    }
}

/** @internal */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public array $updates = [];

    public function getPublicUrl(): string
    {
        return 'https://hub.test/.well-known/mercure';
    }

    public function getFactory(): ?\Symfony\Component\Mercure\Jwt\TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        return 'id';
    }

    /**
     * Both added to HubInterface in symfony/mercure 0.8. Neither is consulted
     * by anything these doubles stand in for — the code under test publishes
     * and nothing else — so they answer with the defaults a real hub uses
     * rather than pretending to a configuration this test does not have.
     */
    public function getProtocolVersion(): \Symfony\Component\Mercure\ProtocolVersion
    {
        return \Symfony\Component\Mercure\ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }
}

/** @internal */
final class ThrowingHub implements HubInterface
{
    public function getPublicUrl(): string
    {
        return 'https://hub.test/.well-known/mercure';
    }

    public function getFactory(): ?\Symfony\Component\Mercure\Jwt\TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        throw new \RuntimeException('the hub is down');
    }

    /** See RecordingHub: added to the interface in symfony/mercure 0.8. */
    public function getProtocolVersion(): \Symfony\Component\Mercure\ProtocolVersion
    {
        return \Symfony\Component\Mercure\ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }
}
