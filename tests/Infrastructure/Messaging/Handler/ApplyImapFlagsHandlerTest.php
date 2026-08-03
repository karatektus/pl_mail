<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyImapFlagsHandler;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Throwable;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/**
 * What an outgoing IMAP push does when the server will not take it.
 *
 * The bug behind these: every failure was logged and swallowed, including a
 * failure to reach the server at all. The archive existed only in the local
 * database, and the comment claimed incoming sync would reconcile it — which
 * is false for an outgoing change, because sync reads the server's state *in*.
 * The next pass read the unchanged mailbox and overwrote the local value, so
 * the user watched their action undo itself.
 *
 * The distinction pinned here is the one that makes the fix safe rather than
 * merely louder: a server that is down gets retried, and a password that is
 * wrong does not — five more rejected logins is how a mailbox gets locked or
 * a host banned.
 */
final class ApplyImapFlagsHandlerTest extends TestCase
{
    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->logged = [];
    }

    public function testAnUnreachableServerEscapesTheHandlerSoMessengerRetriesIt(): void
    {
        $handler = $this->handler(new ConnectionFailedException('connection setup failed'));

        $this->expectException(ConnectionFailedException::class);

        $handler(new ApplyImapFlagsMessage([1 => 10], 'seen'));
    }

    public function testTheUnreachableServerIsAnnouncedBeforeItPropagates(): void
    {
        $handler = $this->handler(new ConnectionFailedException('connection setup failed'));

        try {
            $handler(new ApplyImapFlagsMessage([1 => 10], 'seen'));
        } catch (ConnectionFailedException) {
            // The propagation is the previous test's business.
        }

        self::assertSame(
            [['level' => 'warning', 'message' => 'ApplyImapFlagsHandler: server unreachable, will retry']],
            $this->logged,
        );
    }

    /**
     * Credentials are not a transient condition, and retrying them is actively
     * harmful — so this one stops here, exactly as every failure used to.
     */
    public function testABadPasswordIsLoggedAndNotRetried(): void
    {
        $handler = $this->handler(new AuthFailedException('authentication failed'));

        $handler(new ApplyImapFlagsMessage([1 => 10], 'seen'));

        self::assertSame(
            [['level' => 'error', 'message' => 'ApplyImapFlagsHandler: IMAP error']],
            $this->logged,
        );
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * A handler whose IMAP connection always fails with $failure.
     *
     * Nothing past connect() is reached, which is the whole point: these are
     * about the batch never leaving the building, not about what happens to an
     * individual UID once it has.
     */
    private function handler(Throwable $failure): ApplyImapFlagsHandler
    {
        $account = new Account();
        $account->setUsr(new User());

        $mailbox = new Mailbox();
        $mailbox->setAccount($account);
        $mailbox->setName('INBOX');

        $entity = new Message();
        // The handler keys its work by message id, so the entity needs one and
        // Doctrine is not here to assign it.
        new ReflectionProperty(Message::class, 'id')->setValue($entity, 1);

        $messageRepository = $this->createStub(MessageRepository::class);
        $messageRepository->method('findBy')->willReturn([$entity]);

        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $mailboxRepository->method('find')->willReturn($mailbox);

        $connectionFactory = $this->createStub(ImapConnectionFactory::class);
        $connectionFactory->method('connect')->willThrowException($failure);

        return new ApplyImapFlagsHandler(
            $messageRepository,
            $mailboxRepository,
            $this->logger(),
            $connectionFactory,
        );
    }

    private function logger(): LoggerInterface
    {
        return new class($this->logged) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $logged */
            public function __construct(public array &$logged) {}

            /** @param array<mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }
}
