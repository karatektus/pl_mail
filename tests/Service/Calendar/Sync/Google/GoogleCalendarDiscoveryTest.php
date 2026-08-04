<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Google;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the subscribe screen is offered, and which of those calendars may be
 * written to.
 *
 * Two claims, and the second is the one with teeth. `isReadOnly` is what stops
 * the engine ever pushing — CalendarPusher throws rather than write to a
 * read-only calendar — so a calendar mapped writable that is not means every
 * local edit on it is offered to Google once per sweep and refused, forever,
 * with the user watching an edit that never leaves. Google says which it is in
 * `accessRole`, and the mapping is an allow-list rather than a deny-list so
 * that a role Google invents later is unwritable until somebody decides
 * otherwise.
 *
 * The first claim is smaller but not free: a calendar the user renamed in
 * Google's own interface has to appear under the name they gave it, or they are
 * being asked to subscribe to a calendar they do not recognise.
 *
 * supports() is tested here too, because it is the other half of discovery: a
 * driver that claims too broadly silently steals another provider's calendars,
 * and the registry takes the first driver that says yes.
 */
final class GoogleCalendarDiscoveryTest extends TestCase
{
    public function testEveryCalendarInTheListIsOffered(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [
                [
                    'id'              => 'someone@example.com',
                    'summary'         => 'someone@example.com',
                    'backgroundColor' => '#9fe1e7',
                    'timeZone'        => 'Europe/Berlin',
                    'accessRole'      => 'owner',
                    'primary'         => true,
                ],
                [
                    'id'         => 'de.german#holiday@group.v.calendar.google.com',
                    'summary'    => 'Holidays in Germany',
                    'timeZone'   => 'UTC',
                    'accessRole' => 'reader',
                ],
            ],
        ]));

        $calendars = $fixture->driver->discover(CalendarSource::ofAccount(GoogleDriverFixture::account()));

        self::assertCount(2, $calendars);

        $primary = $calendars[0];

        self::assertSame('someone@example.com', $primary->remoteId);
        self::assertSame('someone@example.com', $primary->name);
        self::assertSame('#9fe1e7', $primary->color);
        self::assertSame('Europe/Berlin', $primary->timeZone);
        self::assertFalse($primary->isReadOnly);
        self::assertTrue($primary->isPrimary);

        $holidays = $calendars[1];

        self::assertSame('de.german#holiday@group.v.calendar.google.com', $holidays->remoteId);
        self::assertTrue($holidays->isReadOnly, 'a reader cannot write, and pushing at one fails on every sweep');
        self::assertFalse($holidays->isPrimary);
        self::assertNull($holidays->color, 'no colour means the provisioner picks from the palette');
    }

    /**
     * @param string $accessRole what Google says this account may do here
     */
    #[DataProvider('accessRoles')]
    public function testOnlyAnOwnerOrAWriterMayBeWrittenTo(string $accessRole, bool $expectedReadOnly): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [['id' => 'c-1', 'summary' => 'Shared', 'accessRole' => $accessRole]],
        ]));

        $calendars = $fixture->driver->discover(CalendarSource::ofAccount(GoogleDriverFixture::account()));

        self::assertSame($expectedReadOnly, $calendars[0]->isReadOnly);
    }

    /**
     * @return iterable<string,array{string,bool}>
     */
    public static function accessRoles(): iterable
    {
        yield 'owner'          => ['owner', false];
        yield 'writer'         => ['writer', false];
        yield 'reader'         => ['reader', true];
        yield 'freeBusyReader' => ['freeBusyReader', true];
        yield 'none'           => ['none', true];
        // Not a role Google has today. It is read-only here because the
        // alternative — assuming a role we have never seen accepts writes — is
        // an edit that is refused on every sweep with nothing on screen to say
        // why.
        yield 'something new'  => ['delegate', true];
        yield 'nothing at all' => ['', true];
    }

    public function testTheNameTheUserGaveACalendarWinsOverItsOwners(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [[
                'id'              => 'c-1',
                'summary'         => 'Team Calendar',
                'summaryOverride' => 'Work',
                'accessRole'      => 'writer',
            ]],
        ]));

        $calendars = $fixture->driver->discover(CalendarSource::ofAccount(GoogleDriverFixture::account()));

        self::assertSame('Work', $calendars[0]->name);
    }

    public function testAColourThatIsNotAHexTripletIsNoColourAtAll(): void
    {
        // Calendar::$color is a seven-character column and every reader assumes
        // #rrggbb. A longer value is truncated by the database and renders as
        // nothing, which looks like a bug in the palette.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [['id' => 'c-1', 'summary' => 'Odd', 'accessRole' => 'owner', 'backgroundColor' => 'rgb(159,225,231)']],
        ]));

        $calendars = $fixture->driver->discover(CalendarSource::ofAccount(GoogleDriverFixture::account()));

        self::assertNull($calendars[0]->color);
    }

    public function testASecondPageOfCalendarsIsFollowed(): void
    {
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json([
                'items'         => [['id' => 'c-1', 'summary' => 'One', 'accessRole' => 'owner']],
                'nextPageToken' => 'page-2',
            ]),
            GoogleDriverFixture::json([
                'items' => [['id' => 'c-2', 'summary' => 'Two', 'accessRole' => 'owner']],
            ]),
        );

        $calendars = $fixture->driver->discover(CalendarSource::ofAccount(GoogleDriverFixture::account()));

        self::assertSame(['c-1', 'c-2'], array_map(
            static fn (RemoteCalendar $calendar): string => $calendar->remoteId,
            $calendars,
        ));
        self::assertStringContainsString('pageToken=page-2', $fixture->url(1));
    }

    public function testAnEmptyListIsAnAnswerRatherThanAFailure(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([]));

        self::assertSame([], $fixture->driver->discover(CalendarSource::ofAccount(GoogleDriverFixture::account())));
    }

    public function testThisDriverSpeaksForGoogleAccountsAndNothingElse(): void
    {
        // The registry takes the first driver that says yes, so a driver that
        // claims too broadly steals another provider's calendars — and the
        // symptom is a Microsoft calendar failing against Google's API.
        $fixture = new GoogleDriverFixture();

        self::assertTrue($fixture->driver->supports(CalendarSource::ofAccount(GoogleDriverFixture::account())));

        $microsoft                = GoogleDriverFixture::account();
        $microsoft->oauthProvider = MailProvider::Microsoft->value;

        self::assertFalse($fixture->driver->supports(CalendarSource::ofAccount($microsoft)));

        // An IMAP mailbox at a Google address is still not a calendar API: the
        // password grant carries no scopes at all.
        $imap           = GoogleDriverFixture::account();
        $imap->authType = AuthType::Password->value;

        self::assertFalse($fixture->driver->supports(CalendarSource::ofAccount($imap)));

        $caldav = new Integration(new User(), Provider::CalDav);

        self::assertFalse($fixture->driver->supports(CalendarSource::ofIntegration($caldav)));
    }
}
