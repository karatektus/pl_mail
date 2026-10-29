<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\Messaging\Handler\ApplyImapKeywordsHandler;
use App\Infrastructure\Messaging\Message\ApplyImapKeywordsMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Imap\ImapConnector;
use App\Service\Imap\ImapPermanentFlags;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A label written to the server as an IMAP keyword (#221 step 2): when it is,
 * where to, and what the server is sent.
 *
 * The propagator half decides: only on an account that has the switch on,
 * only for a label that is a tag there (not a system label, not a folder),
 * and to every server copy the seam names. The handler half writes: SELECT,
 * and `UID STORE ±FLAGS` only in a folder whose PERMANENTFLAGS keep keywords —
 * against the in-memory server, which writes down what it was sent.
 */
final class ImapKeywordWriteTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private Mailbox $inbox;

    /** @var list<FakeFolderServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── deciding ─────────────────────────────────────────────────────────

    public function testATagLabelIsQueuedAsAKeywordOnEveryCopy(): void
    {
        $this->account->labelsAsKeywords = true;
        $message = $this->message(17);
        $label   = $this->label('Follow up');

        $sent = $this->attach($message, $label);

        self::assertCount(1, $sent);
        self::assertInstanceOf(ApplyImapKeywordsMessage::class, $sent[0]);
        self::assertSame(['Follow&ACA-up'], $sent[0]->add);
        self::assertSame([['message' => (int) $message->id, 'mailbox' => (int) $this->inbox->id, 'uid' => 17]], $sent[0]->copies);
        self::assertNull($message->flagsTouchedAt, 'the shared echo guard is not the keyword path\'s to set');
    }

    /** Off by default: nothing leaves plMail. */
    public function testNothingIsWrittenWithTheSwitchOff(): void
    {
        self::assertSame([], $this->attach($this->message(17), $this->label('Follow up')));
    }

    /** A folder label is where the mail is; a system label is not a tag. */
    public function testFolderAndSystemLabelsAreNotKeywords(): void
    {
        $this->account->labelsAsKeywords = true;

        $folder  = new Mailbox();
        $folder->account       = $this->account;
        $folder->name          = 'Clients';
        $folder->fullPath      = 'Clients';
        $folder->delimiter     = '/';
        $folder->isSyncEnabled = true;
        $this->em->persist($folder);
        self::getContainer()->get(LabelResolver::class)->linkFolder($folder, $this->account);
        $this->em->flush();

        self::assertSame([], $this->attach($this->message(17), $folder->label));

        $archive = self::getContainer()->get(LabelResolver::class)->userSystemLabel(LabelRole::Archive, $this->account->usr);
        self::assertSame([], $this->attach($this->message(18), $archive));
    }

    // ── writing ──────────────────────────────────────────────────────────

    public function testTheKeywordIsStoredWhereTheFolderKeepsKeywords(): void
    {
        $server = $this->server();
        $server->keywordFolders['INBOX'] = true;
        $message = $this->message(17);
        // A star the server has not confirmed yet, on the same row.
        $starPending = new \DateTimeImmutable('-1 minute');
        $message->flagsTouchedAt = $starPending;
        $this->em->flush();

        $this->handler($server)(new ApplyImapKeywordsMessage(
            (int) $this->account->id,
            [['message' => (int) $message->id, 'mailbox' => (int) $this->inbox->id, 'uid' => 17]],
            ['Follow&ACA-up'],
        ));

        self::assertSame(['SELECT INBOX', 'STORE 17 +FLAGS (Follow&ACA-up)'], $server->commands);
        self::assertTrue($this->inbox->keywordsAllowed);
        self::assertContains('Follow&ACA-up', $message->flags, 'the mirror says what the server has');
        self::assertEquals($starPending, $message->flagsTouchedAt, 'the star in flight keeps its echo guard');
    }

    public function testAKeywordIsTakenOffAgain(): void
    {
        $server = $this->server();
        $server->keywordFolders['INBOX'] = true;
        $message = $this->message(17, ['\\Seen', 'Follow&ACA-up']);

        $this->handler($server)(new ApplyImapKeywordsMessage(
            (int) $this->account->id,
            [['message' => (int) $message->id, 'mailbox' => (int) $this->inbox->id, 'uid' => 17]],
            [],
            ['Follow&ACA-up'],
        ));

        self::assertSame(['SELECT INBOX', 'STORE 17 -FLAGS (Follow&ACA-up)'], $server->commands);
        self::assertSame(['\\Seen'], $message->flags);
    }

    /** A folder without `\*` gets no STORE: the label stays in plMail there. */
    public function testAFolderThatKeepsNoKeywordsIsLeftAlone(): void
    {
        $server  = $this->server();
        $message = $this->message(17);

        $this->handler($server)(new ApplyImapKeywordsMessage(
            (int) $this->account->id,
            [['message' => (int) $message->id, 'mailbox' => (int) $this->inbox->id, 'uid' => 17]],
            ['Work'],
        ));

        self::assertSame(['SELECT INBOX'], $server->commands);
        self::assertFalse($this->inbox->keywordsAllowed);
        self::assertSame([], $message->flags);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * @return list<object> what the propagator dispatched
     */
    private function attach(Message $message, Label $label): array
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };

        $container = self::getContainer();

        (new LabelChangePropagator(
            $bus,
            $container->get(MailboxRepository::class),
            $container->get(LabelRepository::class),
            new GraphLabelPolicy(),
            new NullLogger(),
            $container->get(MessageRepository::class),
        ))->attachLabel([$message], $label);

        return array_values(array_filter($bus->sent, static fn (object $m): bool => $m instanceof ApplyImapKeywordsMessage));
    }

    /** On the loop the connector runs on, and stopped when the test ends. */
    private function server(): FakeFolderServer
    {
        $server          = new FakeFolderServer(self::getContainer()->get(ImapLoop::class));
        $this->servers[] = $server;

        return $server;
    }

    private function handler(FakeFolderServer $server): ApplyImapKeywordsHandler
    {
        $container = self::getContainer();

        $server->pointAt($this->account);
        $this->em->flush();

        return new ApplyImapKeywordsHandler(
            $container->get(AccountRepository::class),
            $container->get(MailboxRepository::class),
            $container->get(MessageRepository::class),
            $container->get(ImapConnector::class),
            new ImapPermanentFlags(),
            $this->em,
            new NullLogger(),
        );
    }

    /**
     * @param list<string> $flags
     */
    private function message(int $uid, array $flags = []): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->inbox;
        $message->imapUid        = $uid;
        $message->subject        = 'Keyword ' . $uid;
        $message->bodyText       = 'x';
        $message->hasAttachments = false;
        $message->flags          = $flags;
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function label(string $name): Label
    {
        $label = new Label();
        $label->usr  = $this->account->usr;
        $label->name = $name;
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seed(): void
    {
        $user = new User();
        $user->email = 'keywords-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Key';
        $user->nameLast = 'Words';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'keywords-fixture@example.test';
        $account->username = 'keywords-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $inbox = new Mailbox();
        $inbox->account       = $account;
        $inbox->name          = 'INBOX';
        $inbox->fullPath      = 'INBOX';
        $inbox->delimiter     = '/';
        $inbox->isSyncEnabled = true;
        $this->em->persist($inbox);

        $this->em->flush();
        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;
        $this->inbox   = $inbox;
    }
}
