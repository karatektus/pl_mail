<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Domain\Enum\Job\JobKind;
use App\Service\Job\JobNotifier;
use App\Service\Mail\SyncNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * A hub that is down must not fail the work it was asked to announce.
 *
 * THE BUG THIS PINS
 * ─────────────────
 * A Mercure hub was briefly unreachable on a live install, and a whole
 * SyncAccountMessage failed with it — mail fetched, threaded and committed,
 * then handed back to Messenger for retry because the update saying "your
 * mailbox changed" could not be sent. The doorbell failing does not un-deliver
 * the parcel, but that is precisely what a thrown exception told the worker.
 *
 * Five of the seven publishers had no guard at all. Two of those are worse
 * than the one that was reported: JobNotifier is called from the failure
 * handler of a background job, where an exception would replace the real error
 * with this one, and SendOutcomeNotifier announces the result of a SEND.
 *
 * So this is not about Mercure. It is about the rule that a notification is
 * never allowed to be the thing that fails.
 */
final class HubOutageTest extends TestCase
{
    public function testAnAccountSyncSurvivesADeadHub(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [$level, (string) $message];
            }
        };

        $notifier = new SyncNotifier($this->deadHub(), $logger);

        $user     = new User();
        $account  = new Account();
        $mailbox  = new Mailbox();

        $account->usr    = $user;
        $mailbox->account = $account;

        // The assertion IS that this returns. Before the fix it threw, and the
        // sync that had already finished was retried from the beginning.
        $notifier->publishMailboxSynced($account, $mailbox);
        $notifier->publishAccountSynced($account);

        self::assertCount(2, $logger->lines, 'silently swallowed is the other half of this bug');
        self::assertSame('warning', $logger->lines[0][0], 'a hub outage is worth an operator seeing');
        self::assertStringContainsString('publish failed', $logger->lines[0][1]);
    }

    /**
     * The one that would have replaced a real error with this one:
     * RunBulkStatusHandler calls JobNotifier from inside its catch block.
     */
    public function testABackgroundJobNotificationSurvivesADeadHub(): void
    {
        $logger = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->count;
            }
        };

        $user = new User();
        $user->email = 'x@example.test';

        $job = new BackgroundJob($user, JobKind::MarkRead);

        // No id on an unpersisted user, so this returns before publishing —
        // give it one the way the database would.
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, 42);

        (new JobNotifier($this->deadHub(), $logger))->changed($job);

        self::assertSame(1, $logger->count, 'the outage is recorded rather than thrown');
    }

    /** A hub that refuses everything, the way an unreachable one does. */
    private function deadHub(): HubInterface
    {
        return new class implements HubInterface {
            public function getUrl(): string
            {
                return 'http://mercure/.well-known/mercure';
            }

            public function getPublicUrl(): string
            {
                return $this->getUrl();
            }

            public function getProvider(): \Symfony\Component\Mercure\Jwt\TokenProviderInterface
            {
                throw new \LogicException('not used');
            }

            public function getFactory(): ?\Symfony\Component\Mercure\Jwt\TokenFactoryInterface
            {
                return null;
            }

            public function getProtocolVersion(): \Symfony\Component\Mercure\ProtocolVersion
            {
                return \Symfony\Component\Mercure\ProtocolVersion::V1;
            }

            public function getCookieName(): string
            {
                return 'mercureAuthorization';
            }

            public function publish(Update $update): string
            {
                throw new MercureException('Failed to send an update.');
            }
        };
    }
}
