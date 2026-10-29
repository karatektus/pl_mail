<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Infrastructure\ImapClient\ImapLoop;
use App\Service\Imap\ImapConnector;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\ImapCopies;
use App\Service\Imap\MailboxSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessageEraser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A folder that is the server's view of mail it keeps elsewhere is not synced
 * by default, and the second copies it stored are erased (#414).
 *
 * The report: a Proton Bridge account whose conversations showed every message
 * twice, once from its own folder and once from All Mail. A message row is one
 * folder copy, so syncing All Mail stores everything again.
 *
 * The fixture is a Proton-shaped listing, "/" delimited:
 *
 *   INBOX       \Inbox
 *   Sent        \Sent
 *   Archive     \Archive
 *   All Mail    \All       <- a mirror, because Archive is there
 *   Starred     \Flagged   <- a mirror always
 */
final class MailboxMirrorFolderTest extends KernelTestCase
{
    private const string DELIMITER = '/';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAllMailBesideAnArchiveAndStarredAreCreatedWithSyncOff(): void
    {
        $this->sync($this->protonListing());

        // Presence: the real folders are still synced. Without this the
        // assertions below would also pass for a syncer that enabled nothing.
        self::assertSame(['Archive', 'INBOX', 'Sent'], $this->enabledPaths());

        self::assertTrue($this->mailbox('All Mail')->mirrorsOtherFolders);
        self::assertTrue($this->mailbox('Starred')->mirrorsOtherFolders);
        self::assertFalse($this->mailbox('Archive')->mirrorsOtherFolders);
    }

    /**
     * Gmail over IMAP: no Archive folder, so archived mail lives in All Mail
     * and nowhere else. Switching it off would hide that mail.
     */
    public function testAllMailWithoutAnArchiveIsSyncedAsBefore(): void
    {
        $listing = $this->protonListing();
        unset($listing['Archive']);

        $this->sync($listing);

        self::assertFalse($this->mailbox('All Mail')->mirrorsOtherFolders);
        self::assertTrue($this->mailbox('All Mail')->isSyncEnabled);
        // Starred is a mirror with or without an archive.
        self::assertFalse($this->mailbox('Starred')->isSyncEnabled);
    }

    /**
     * RFC 3501 treats attributes as case-insensitive.
     *
     * @return iterable<string, array{string}>
     */
    public static function allSpellings(): iterable
    {
        yield 'as RFC 6154 writes it' => ['\\All'];
        yield 'upper case'            => ['\\ALL'];
        yield 'lower case'            => ['\\all'];
    }

    #[DataProvider('allSpellings')]
    public function testTheAttributeIsMatchedWithoutRegardToCase(string $attribute): void
    {
        $listing = $this->protonListing();
        unset($listing['All Mail']);
        $listing['Everything'] = ['\\HasNoChildren', $attribute];

        $this->sync($listing);

        self::assertTrue($this->mailbox('Everything')->mirrorsOtherFolders);
        self::assertFalse($this->mailbox('Everything')->isSyncEnabled);
    }

    /**
     * A server that does not report special-use attributes still calls the
     * folder All Mail, as it calls Sent "Sent".
     */
    public function testAllMailIsRecognisedByNameWhenTheServerDoesNotSay(): void
    {
        $listing = $this->protonListing();
        $listing['All Mail'] = ['\\HasNoChildren'];

        $this->sync($listing);

        self::assertTrue($this->mailbox('All Mail')->mirrorsOtherFolders);
        self::assertFalse($this->mailbox('All Mail')->isSyncEnabled);
    }

    /**
     * Installs that synced All Mail before the rule: the migration leaves the
     * folder unmarked and on, and the next folder sync switches it off. Once —
     * a person who switches it back on keeps it on.
     */
    public function testAFolderSyncedBeforeTheRuleIsSwitchedOffOnceAndMayBeSwitchedBackOn(): void
    {
        $this->sync($this->protonListing());

        // The state the migration leaves an existing install in.
        $allMail = $this->mailbox('All Mail');
        $allMail->mirrorsOtherFolders = false;
        $allMail->isSyncEnabled       = true;
        $this->em->flush();

        $this->sync($this->protonListing());

        self::assertFalse($this->mailbox('All Mail')->isSyncEnabled, 'switched off on first recognition');

        $this->mailbox('All Mail')->isSyncEnabled = true;
        $this->em->flush();

        $this->sync($this->protonListing());

        self::assertTrue($this->mailbox('All Mail')->isSyncEnabled, 'and not again');
    }

    /**
     * The reported conversation, and the copies that must survive the
     * clean-up beside the one that must not.
     */
    public function testTheCopiesAMirrorStoredOfMailKeptElsewhereAreErased(): void
    {
        $this->sync($this->protonListing());

        $inbox   = $this->mailbox('INBOX');
        $archive = $this->mailbox('Archive');
        $allMail = $this->mailbox('All Mail');
        $starred = $this->mailbox('Starred');

        // In INBOX and All Mail: the duplicate #414 reported.
        $twice       = MessageIdHelper::mint('kunde@example.test');
        $twiceThread = $this->thread('Zweimal');
        $this->rowIn($inbox, $twice, 1, $twiceThread);
        $this->rowIn($allMail, $twice, 101, $twiceThread);

        // Only in All Mail: its one copy, which must stay.
        $only = MessageIdHelper::mint('kunde@example.test');
        $this->rowIn($allMail, $only, 102, $this->thread('Nur hier'));

        // In All Mail, and in Archive waiting for its UID after a move. The
        // Archive row has no confirmed address, so the All Mail copy is the
        // only one that has, and it stays.
        $moving       = MessageIdHelper::mint('kunde@example.test');
        $movingThread = $this->thread('Unterwegs');
        $this->rowIn($archive, $moving, null, $movingThread);
        $this->rowIn($allMail, $moving, 103, $movingThread);

        // In Starred and All Mail and no real folder: both are mirrors, and
        // neither may count as the other's "kept elsewhere".
        $starredOnly   = MessageIdHelper::mint('kunde@example.test');
        $starredThread = $this->thread('Markiert');
        $this->rowIn($starred, $starredOnly, 5, $starredThread);
        $this->rowIn($allMail, $starredOnly, 104, $starredThread);

        $this->em->flush();

        $this->sync($this->protonListing());

        self::assertSame(['INBOX'], $this->foldersHolding($twice), 'one message, one row');
        self::assertSame(1, $twiceThread->messageCount, 'and the conversation counts one');

        self::assertSame(['All Mail'], $this->foldersHolding($only));
        self::assertSame(['All Mail', 'Archive'], $this->foldersHolding($moving));
        self::assertSame(['All Mail', 'Starred'], $this->foldersHolding($starredOnly));
    }

    /**
     * Switched back on, the folder's copies are what was asked for.
     */
    public function testNothingIsErasedFromAMirrorSomebodySwitchedBackOn(): void
    {
        $this->sync($this->protonListing());

        $this->mailbox('All Mail')->isSyncEnabled = true;

        $twice  = MessageIdHelper::mint('kunde@example.test');
        $thread = $this->thread('Zweimal');
        $this->rowIn($this->mailbox('INBOX'), $twice, 1, $thread);
        $this->rowIn($this->mailbox('All Mail'), $twice, 101, $thread);
        $this->em->flush();

        $this->sync($this->protonListing());

        self::assertSame(['All Mail', 'INBOX'], $this->foldersHolding($twice));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @return array<string, list<string>> raw path => LIST attributes
     */
    private function protonListing(): array
    {
        return [
            'INBOX'    => ['\\HasNoChildren'],
            'Sent'     => ['\\HasNoChildren', '\\Sent'],
            'Archive'  => ['\\HasNoChildren', '\\Archive'],
            'All Mail' => ['\\HasNoChildren', '\\All'],
            'Starred'  => ['\\HasNoChildren', '\\Flagged'],
        ];
    }

    /**
     * @param array<string, list<string>> $listing
     *
     * @return array{created: int, updated: int, deleted: int, renamed: int, missing: int}
     */
    private function sync(array $listing): array
    {
        $container = self::getContainer();

        // The LIST another server would give, which the test stack's Dovecot
        // cannot be made to: see FakeImapServer.
        new FakeImapServer($container->get(ImapLoop::class), $listing, self::DELIMITER)->pointAt($this->account);

        $syncer = new MailboxSyncer(
            $container->get(MailboxRepository::class),
            $this->em,
            $container->get(ImapConnector::class),
            $container->get(LabelResolver::class),
            $container->get(MessageRepository::class),
            $container->get(MessageEraser::class),
            $container->get(ImapCopies::class),
            new NullLogger(),
        );

        return $syncer->syncForAccount($this->account);
    }

    private function mailbox(string $fullPath): Mailbox
    {
        $mailbox = $this->em->getRepository(Mailbox::class)->findOneBy([
            'account'  => $this->account,
            'fullPath' => $fullPath,
        ]);

        self::assertNotNull($mailbox, sprintf('no mailbox row for %s', $fullPath));

        return $mailbox;
    }

    /**
     * What ImapAccountSyncer would poll: the account's sync-enabled folders.
     *
     * @return list<string>
     */
    private function enabledPaths(): array
    {
        $paths = array_map(
            static fn(Mailbox $mailbox): string => (string) $mailbox->fullPath,
            $this->em->getRepository(Mailbox::class)->findBy([
                'account'       => $this->account,
                'isSyncEnabled' => true,
            ]),
        );

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string> the folders this account holds a row of the message in, sorted
     */
    private function foldersHolding(string $messageId): array
    {
        /** @var list<array{fullPath: string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT mb.fullPath FROM ' . Message::class . ' m JOIN m.mailbox mb'
            . ' WHERE m.account = :account AND m.messageId = :messageId',
        )
            ->setParameter('account', $this->account)
            ->setParameter('messageId', $messageId)
            ->getArrayResult();

        $paths = array_map(static fn(array $row): string => $row['fullPath'], $rows);
        sort($paths);

        return $paths;
    }

    private function thread(string $subject): MessageThread
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 0;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;

        $this->em->persist($thread);

        return $thread;
    }

    private function rowIn(Mailbox $mailbox, string $messageId, ?int $uid, MessageThread $thread): Message
    {
        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $mailbox;
        $message->imapUid        = $uid;
        $message->messageId      = $messageId;
        $message->subject        = $thread->subject;
        $message->fromAddress    = 'kunde@example.test';
        $message->bodyText       = (string) $thread->subject;
        $message->hasAttachments = false;
        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable('2026-10-20 09:00:00');
        $message->sentAt         = $message->receivedAt;

        $this->em->persist($message);

        $thread->addMessage($message);
        $thread->messageCount = $thread->messageCount + 1;

        return $message;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'mirror-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Mirror';
        $user->nameLast = 'Folder';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Mirror Folder Fixture';
        $account->username = 'mirror-fixture@example.test';
        $account->imapHost = '127.0.0.1';
        $account->imapPort = 1143;
        $account->imapEncryption = 'starttls';
        $account->smtpHost = '127.0.0.1';
        $account->smtpPort = 1025;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
