<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\CalendarPushSubscriptionManagerInterface;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Service\Calendar\Push\CalendarPushRegistry;
use App\Service\Push\PushSubscriptionRegistry;
use App\Service\Push\PushTeardown;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A teardown hands back what it registered — and never refuses to run because
 * the provider would not take it back.
 *
 * WHY THIS EXISTS
 * ───────────────
 * A push registration lives in two places: a row here, and a channel the
 * provider holds. A reset truncated the rows and left the channels live, so
 * Google went on POSTing about calendars that no longer existed and Microsoft
 * went on POSTing about subscriptions nothing could resolve. The install that
 * produced this had a log full of "notification for an unknown channel" that
 * nothing could stop, because the tokens needed to call the channels off had
 * been truncated along with everything else.
 *
 * THE TWO HALVES, AND WHY THE SECOND MATTERS AS MUCH
 * ─────────────────────────────────────────────────
 * Revoking first is the fix. Revoking BEST-EFFORT is what keeps the fix from
 * being worse than the bug: the tokens may already be dead and the provider may
 * 404 a channel that expired on its own, and a reset that refuses to run
 * because Google is unreachable is a far worse failure than a stale channel —
 * which lapses by itself within a week anyway.
 */
final class ResetRevokesPushTest extends TestCase
{
    public function testEveryAccountAndCalendarIsRevoked(): void
    {
        $accountManager  = $this->accountManager();
        $calendarManager = $this->calendarManager();

        $teardown = $this->makeTeardown($accountManager, $calendarManager);

        $revoked = $teardown->forAccounts([new Account(), new Account()])
            + $teardown->forCalendars([new Calendar(), new Calendar(), new Calendar()]);

        self::assertSame(5, $revoked);
        self::assertSame(2, $accountManager->calls);
        self::assertSame(3, $calendarManager->calls);
    }

    /**
     * The important one. A provider that throws does not stop the rest being
     * revoked, and above all does not propagate out to abort the deletion the
     * caller is in the middle of.
     */
    public function testAFailedRevocationDoesNotStopTheRest(): void
    {
        $accountManager         = $this->accountManager();
        $accountManager->throw  = true;
        $calendarManager        = $this->calendarManager();

        $teardown = $this->makeTeardown($accountManager, $calendarManager);

        $revoked = $teardown->forAccounts([new Account(), new Account()]);

        self::assertSame(0, $revoked, 'nothing is claimed to have been revoked');
        self::assertSame(2, $accountManager->calls, 'and both were still attempted');

        // The calendars that follow are unaffected by the accounts that failed.
        self::assertSame(1, $teardown->forCalendars([new Calendar()]));
    }

    /**
     * A provider with no push manager — an IMAP account, a CalDAV mirror, a
     * hand-made local calendar — is skipped quietly. Nothing was ever
     * registered, so there is nothing to hand back and nothing to report.
     */
    public function testSubjectsWithNoPushManagerAreSkippedQuietly(): void
    {
        $teardown = new PushTeardown(
            new PushSubscriptionRegistry([]),
            new CalendarPushRegistry([]),
            new NullLogger(),
        );

        self::assertSame(0, $teardown->forAccounts([new Account()]));
        self::assertSame(0, $teardown->forCalendars([new Calendar()]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeTeardown(object $accountManager, object $calendarManager): PushTeardown
    {
        return new PushTeardown(
            new PushSubscriptionRegistry([$accountManager]),
            new CalendarPushRegistry([$calendarManager]),
            new NullLogger(),
        );
    }

    private function accountManager(): object
    {
        return new class implements PushSubscriptionManagerInterface {
            public int $calls = 0;
            public bool $throw = false;

            public function supports(Account $account): bool { return true; }
            public function isConfigured(): bool { return true; }
            public function messageKey(): string { return 'test'; }
            public function subscribe(Account $account): bool { return true; }
            public function renew(Account $account): bool { return true; }
            public function needsRenewal(Account $account): bool { return false; }
            public function expiresAt(Account $account): ?DateTimeImmutable { return null; }
            public function health(Account $account): PushHealth { return PushHealth::Active; }

            public function unsubscribe(Account $account): void
            {
                ++$this->calls;

                if (true === $this->throw) {
                    // What a dead token or an unreachable provider looks like
                    // from in here.
                    throw new RuntimeException('provider said no');
                }
            }
        };
    }

    private function calendarManager(): object
    {
        return new class implements CalendarPushSubscriptionManagerInterface {
            public int $calls = 0;

            public function supports(Calendar $calendar): bool { return true; }
            public function isConfigured(): bool { return true; }
            public function subscribe(Calendar $calendar): bool { return true; }
            public function renew(Calendar $calendar): bool { return true; }
            public function needsRenewal(Calendar $calendar): bool { return false; }
            public function expiresAt(Calendar $calendar): ?DateTimeImmutable { return null; }
            public function health(Calendar $calendar): PushHealth { return PushHealth::Active; }

            public function unsubscribe(Calendar $calendar): void
            {
                ++$this->calls;
            }
        };
    }
}
