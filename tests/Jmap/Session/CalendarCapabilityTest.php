<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Jmap\Method\MethodRegistry;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * Calendars are advertised under a vendor URN, and under exactly one account.
 *
 * **Not "urn:ietf:params:jmap:calendars".** JMAP for Calendars is an unratified
 * draft whose object shape is still moving, so claiming its URN would promise a
 * contract this server cannot hold and a client written against a later revision
 * would break on. The push extension made the same call for the same reason, and
 * the test asserts the vendor spelling rather than merely "some calendar
 * capability" — a well-meaning change to the standard URN is exactly the edit
 * this is here to stop.
 *
 * The second claim is arithmetic rather than taste. A plMail Calendar belongs to
 * the user, a JMAP account is one mail account, and a client keys every object
 * by (accountId, id) — so advertising calendars on all three of a user's
 * accounts would draw each calendar three times with no way to tell they are
 * one.
 */
final class CalendarCapabilityTest extends JmapTestCase
{
    private SessionBuilder $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = self::getContainer()->get(SessionBuilder::class);
    }

    public function testTheSessionAdvertisesTheVendorCalendarUrn(): void
    {
        $session = $this->sessions->build($this->user);

        self::assertArrayHasKey('urn:plmail:params:jmap:calendars', $session['capabilities']);
        self::assertArrayNotHasKey(
            'urn:ietf:params:jmap:calendars',
            $session['capabilities'],
            'the draft URN promises a shape that is still moving',
        );
    }

    /** A capability a client may not declare in "using" is a capability it cannot call. */
    public function testAClientMayDeclareItInUsing(): void
    {
        self::assertContains(Capability::CALENDARS, Capability::SUPPORTED);
    }

    /**
     * And a capability nothing can serve is worse than no capability, because a
     * client discovers it by calling.
     *
     * Methods reach the registry by DI tag, so a class that exists, compiles and
     * is fully tested is still unreachable if autoconfiguration stops applying to
     * its directory — a failure that shows up as unknownMethod at runtime and
     * nowhere in a suite that calls the classes directly.
     */
    public function testEveryAdvertisedMethodIsRegistered(): void
    {
        $registry = self::getContainer()->get(MethodRegistry::class);

        foreach (['Calendar/get', 'CalendarEvent/get', 'CalendarEvent/query', 'CalendarEvent/set'] as $name) {
            self::assertNotNull($registry->get($name), sprintf('"%s" is advertised but not registered', $name));
        }
    }

    public function testTheCalendarAccountIsTheOneTheSessionNamesAsPrimary(): void
    {
        $session = $this->sessions->build($this->user);

        self::assertSame($this->accountId(), $session['primaryAccounts'][Capability::CALENDARS]);
        self::assertArrayHasKey(
            Capability::CALENDARS,
            $session['accounts'][$this->accountId()]['accountCapabilities'],
        );
    }

    /**
     * The other accounts stay mail-only. Without this the same calendar is
     * published once per connected account, under the same id, and a client
     * draws it as many times as the user has mailboxes.
     */
    public function testASecondAccountDoesNotAlsoAdvertiseCalendars(): void
    {
        $second = $this->secondAccount();

        $session = $this->sessions->build($this->user);

        self::assertArrayNotHasKey(
            Capability::CALENDARS,
            $session['accounts'][(string) $second->id]['accountCapabilities'],
        );
        self::assertArrayHasKey(
            Capability::MAIL,
            $session['accounts'][(string) $second->id]['accountCapabilities'],
        );
    }

    /**
     * The get limit is advertised because it is lower than the Session's global
     * maxObjectsInGet: a client obeying 500 would otherwise meet a
     * requestTooLarge it was told not to expect.
     */
    public function testTheGetLimitIsStatedRatherThanDiscovered(): void
    {
        $session = $this->sessions->build($this->user);
        $calendars = $session['accounts'][$this->accountId()]['accountCapabilities'][Capability::CALENDARS];

        self::assertLessThan($session['capabilities'][Capability::CORE]['maxObjectsInGet'], $calendars['maxEventsInGet']);
    }
}
