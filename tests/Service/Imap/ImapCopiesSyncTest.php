<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\ImapClient\ImapSession;
use App\Service\Imap\ImapUidPresence;
use App\Service\Imap\MessageSyncer;
use App\Service\Imap\PurgedCopies;
use App\Service\Imap\VanishedMessageReconciler;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessagePurger;
use App\Tests\Infrastructure\ImapClient\UsesDovecot;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One email kept in several folders is one message with several locations,
 * synced against a real server (#415).
 *
 * Each case puts mail on Dovecot the way a client or a server would — APPEND,
 * COPY, EXPUNGE — runs the folder sync plMail runs, and reads back what was
 * stored. The case that matters most for #414 is the first: before #415 the
 * second folder's sync downloaded the copy and stored it as a second message.
 */
final class ImapCopiesSyncTest extends KernelTestCase
{
    use SeedsMarkerFixtures;
    use UsesDovecot;

    private Connection $connection;
    private MessageSyncer $syncer;
    private ImapSession $session;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->syncer     = static::getContainer()->get(MessageSyncer::class);

        $this->session = $this->dovecotSession();

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        $this->session->logout();

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testACopyInASecondFolderBecomesALocationOfTheSameMessage(): void
    {
        $inbox   = $this->folder('INBOX');
        $archive = $this->folder('Archive');

        $uid = $this->session->append('INBOX', $this->mail('one'));
        $this->sync($inbox);

        $copied = $this->session->uidCopy('INBOX', (string) $uid, 'Archive');
        $this->sync($archive);

        $message = $this->onlyMessage('one');

        self::assertSame([['INBOX', $uid], ['Archive', $copied[$uid]]], $this->locations($message));
        self::assertSame('INBOX', $message->mailbox?->fullPath, 'the first folder stays primary');
        self::assertNotNull($message->rfc822Size);
        self::assertSame($copied[$uid], $this->reloaded($archive)->lastSeenUid, 'the copy moved the mark, so it is not asked for again');
    }

    /**
     * Proton's All Mail, synced before the folder the mail was filed in: the
     * folder takes over as primary when its copy turns up.
     */
    public function testAMirrorsCopyIsNeverPrimaryOverARealFolder(): void
    {
        $allMail = $this->folder('All Mail', mirror: true);
        $inbox   = $this->folder('INBOX');

        $uid = $this->session->append('INBOX', $this->mail('two'));
        $this->session->uidCopy('INBOX', (string) $uid, 'All Mail');

        $this->sync($allMail);
        $this->sync($inbox);

        $message = $this->onlyMessage('two');

        self::assertCount(2, $message->locations);
        self::assertSame('INBOX', $message->mailbox?->fullPath);
    }

    /**
     * A message you Cc'd to yourself: one Message-ID, two messages, as before.
     * The delivered copy has gained a Received: header, so the sizes differ.
     */
    public function testTheSameMessageIdWithOtherBytesStaysAMessageOfItsOwn(): void
    {
        $sent  = $this->folder('Sent');
        $inbox = $this->folder('INBOX');

        $this->session->append('Sent', $this->mail('three'));
        $this->session->append('INBOX', "Received: from mx.example.test by mail.example.test\r\n" . $this->mail('three'));

        $this->sync($sent);
        $this->sync($inbox);

        self::assertCount(2, $this->messages('three'));
    }

    /**
     * Removed from one folder in another client while the server keeps it in
     * another: the copy goes at once, the email stays, and the remaining copy
     * becomes primary. Nothing waits for coverage — that is for an email's
     * last copy.
     */
    public function testLosingOneCopyKeepsTheEmailAndItsOtherCopy(): void
    {
        $inbox   = $this->folder('INBOX');
        $archive = $this->folder('Archive');

        $uid = $this->session->append('INBOX', $this->mail('four'));
        // A second message, so the folder does not list empty after the
        // expunge: an empty listing is refused as a rebuild, not a purge.
        $this->session->append('INBOX', $this->mail('four-neighbour'));
        $this->sync($inbox);

        $copied = $this->session->uidCopy('INBOX', (string) $uid, 'Archive');
        $this->sync($archive);

        $this->session->uidStore('INBOX', (string) $uid, '+', ['\\Deleted']);
        $this->session->expunge('INBOX', (string) $uid);

        $inbox          = $this->reloaded($inbox);
        $inbox->sweptAt = null;
        static::getContainer()->get(VanishedMessageReconciler::class)->sweep($inbox, $this->session);
        $this->em->clear();

        $message = $this->onlyMessage('four');

        self::assertSame([['Archive', $copied[$uid]]], $this->locations($message));
        self::assertSame('Archive', $message->mailbox?->fullPath);
        self::assertNull($message->vanishedAt, 'not missing: the server still keeps it');
    }

    /**
     * Another client marks the INBOX copy read and leaves the Archive copy
     * alone. The email is read: that copy changed. And it stays read when
     * Archive is listed next — that copy has not changed, it only disagrees,
     * and copies that disagree used to take turns setting the email.
     */
    public function testAChangeToOneCopyStandsAgainstACopyThatDidNotChange(): void
    {
        $inbox   = $this->folder('INBOX');
        $archive = $this->folder('Archive');

        $uid    = $this->session->append('INBOX', $this->mail('five'));
        $copied = $this->session->uidCopy('INBOX', (string) $uid, 'Archive');
        $this->sync($inbox);
        $this->sync($archive);

        self::assertNull($this->onlyMessage('five')->seenAt, 'unread to begin with');

        $this->session->uidStore('INBOX', (string) $uid, '+', ['\\Seen']);

        $this->sweep($inbox);
        self::assertNotNull($this->onlyMessage('five')->seenAt, 'read: a copy was marked read');

        $this->sweep($archive);
        self::assertNotNull($this->onlyMessage('five')->seenAt, 'still read: the Archive copy did not change');

        // The Archive copy is read too now, then marked unread: its change is
        // the latest, and it wins in turn.
        $this->session->uidStore('Archive', (string) $copied[$uid], '+', ['\\Seen']);
        $this->sweep($archive);
        $this->session->uidStore('Archive', (string) $copied[$uid], '-', ['\\Seen']);
        $this->sweep($archive);
        self::assertNull($this->onlyMessage('five')->seenAt, 'and a later change to the other copy wins in turn');
    }

    /**
     * #427, the worst case: the server has moved the mail to Trash, the row
     * still names its INBOX address (the move's answer has not been written
     * yet), and the user deletes it forever. The Trash copy has a UID nobody
     * recorded — and it must not come back on the next Trash sync, and must
     * go on the server.
     */
    public function testMailDeletedForeverDoesNotComeBackFromAMoveThatLandedFirst(): void
    {
        $inbox = $this->folder('INBOX');
        $trash = $this->folder('Trash');

        $uid = $this->session->append('INBOX', $this->mail('six'));
        $this->sync($inbox);

        $this->session->uidMove('INBOX', (string) $uid, 'Trash');

        static::getContainer()->get(MessagePurger::class)->purge([$this->onlyMessage('six')]);
        $this->em->clear();

        $this->sync($trash);

        self::assertCount(0, $this->messages('six'), 'not imported again');

        // Past the purge job's time: the sync asks the server itself.
        $later = new DateTimeImmutable('+15 minutes');
        $copies = static::getContainer()->get(PurgedCopies::class);
        $copies->settle($this->reloaded($trash), $this->session, $later);
        $copies->settle($this->reloaded($inbox), $this->session, $later);

        self::assertSame([], $this->session->uidSearch('Trash', 'ALL'), 'and gone on the server');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM purged_copy p JOIN mailbox mb ON mb.id = p.mailbox_id WHERE mb.account_id = ?', [$this->account->id]), 'and nothing left on record');
    }

    /**
     * #427, the other report: Trash emptied in webmail stays in plMail. The
     * copy has a real address, so the sweep sees it go and the reaper — every
     * folder of the account having been listed since — removes it.
     */
    public function testMailDeletedFromTrashOnTheServerIsReaped(): void
    {
        $trash = $this->folder('Trash');

        $uid = $this->session->append('Trash', $this->mail('seven'));
        $this->sync($trash);
        self::assertCount(1, $this->messages('seven'));

        $this->session->uidStore('Trash', (string) $uid, '+', ['\\Deleted']);
        $this->session->expunge('Trash', (string) $uid);

        // Missing at one listing, and still missing at the next: the reaper
        // acts on a folder listed since the copy went, never on one listing.
        $this->sweep($trash);
        $this->sweep($trash, new DateTimeImmutable('+1 minute'));

        $vanished = static::getContainer()->get(VanishedMessageReconciler::class);
        $account  = $this->em->find(Account::class, $this->account->id);
        self::assertInstanceOf(Account::class, $account);

        $vanished->reap($account, new ImapUidPresence($this->session, new NullLogger()));
        $this->em->clear();

        self::assertCount(0, $this->messages('seven'));
    }

    /**
     * Mail stored before #415 has no size, and a copy of it elsewhere was
     * stored again. The folder's listing gives it one; from then on its copy
     * in another folder is recognised.
     */
    public function testTheSweepGivesOlderMailItsSizeSoItsCopiesAreRecognised(): void
    {
        $inbox   = $this->folder('INBOX');
        $archive = $this->folder('Archive');

        $uid = $this->session->append('INBOX', $this->mail('eight'));
        $this->sync($inbox);

        // As a row from before #415 is.
        $this->connection->executeStatement('UPDATE message SET rfc822_size = NULL WHERE account_id = ?', [$this->account->id]);

        $this->sweep($inbox);
        self::assertNotNull($this->onlyMessage('eight')->rfc822Size);

        $this->session->uidCopy('INBOX', (string) $uid, 'Archive');
        $this->sync($archive);

        self::assertCount(2, $this->onlyMessage('eight')->locations);
    }

    /**
     * An email kept only in All Mail is archived, which is what Gmail means by
     * it, and All Mail is no label of its own. A copy turning up in INBOX says
     * where the email is instead; losing that copy archives it again.
     */
    public function testMailOnlyInAllMailIsArchivedUntilARealFolderKeepsIt(): void
    {
        $allMail = $this->folder('All Mail', mirror: true);
        $inbox   = $this->folder('INBOX');

        $resolver = static::getContainer()->get(LabelResolver::class);
        $resolver->bindMailbox($resolver->systemLabel(LabelRole::Inbox, $this->account), $inbox);
        $this->em->flush();

        $this->session->append('All Mail', $this->mail('nine'));
        $this->sync($allMail);

        self::assertSame(['archive'], $this->roles($this->onlyMessage('nine')), 'archived, and not labelled All Mail');

        $uid = $this->session->append('INBOX', $this->mail('nine'));
        $this->sync($inbox);

        self::assertSame(['inbox'], $this->roles($this->onlyMessage('nine')), 'in the inbox, and no longer archived');

        // Another neighbour, so the listing that loses the copy is not empty.
        $this->session->append('INBOX', $this->mail('nine-neighbour'));
        $this->sync($inbox);
        $this->session->uidStore('INBOX', (string) $uid, '+', ['\\Deleted']);
        $this->session->expunge('INBOX', (string) $uid);
        $this->sweep($inbox);

        self::assertSame(['archive'], $this->roles($this->onlyMessage('nine')), 'archived again');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function folder(string $path, bool $mirror = false): Mailbox
    {
        if ('INBOX' !== $path) {
            $this->session->create($path);
        }

        $mailbox                      = new Mailbox();
        $mailbox->account             = $this->account;
        $mailbox->name                = $path;
        $mailbox->fullPath            = $path;
        $mailbox->isSyncEnabled       = true;
        $mailbox->isIdleEnabled       = false;
        $mailbox->mirrorsOtherFolders = $mirror;
        // Imported already, so the incremental pass reads the folder from its
        // first UID rather than planning a history import.
        $mailbox->importFloorUid      = 0;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function sync(Mailbox $mailbox): void
    {
        $this->syncer->syncMailbox($this->reloaded($mailbox), $this->session);
        $this->em->clear();
    }

    private function sweep(Mailbox $mailbox, ?DateTimeImmutable $now = null): void
    {
        $mailbox          = $this->reloaded($mailbox);
        $mailbox->sweptAt = null;

        static::getContainer()->get(VanishedMessageReconciler::class)->sweep($mailbox, $this->session, $now);
        $this->em->clear();
    }

    /**
     * @return list<string> the role values of the system labels the message wears, sorted
     */
    private function roles(Message $message): array
    {
        $roles = [];

        foreach ($message->labels as $label) {
            if (null !== $label->role) {
                $roles[] = $label->role->value;
            }
        }

        sort($roles);

        return $roles;
    }

    private function reloaded(Mailbox $mailbox): Mailbox
    {
        $found = $this->em->find(Mailbox::class, $mailbox->id);
        self::assertInstanceOf(Mailbox::class, $found);

        return $found;
    }

    private function mail(string $name): string
    {
        return "From: Sender <sender@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Copy {$name}\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: <copy-{$name}@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "The {$name} copy.\r\n";
    }

    /**
     * @return list<Message>
     */
    private function messages(string $name): array
    {
        return $this->em->getRepository(Message::class)->findBy(['account' => $this->account->id, 'messageId' => "copy-{$name}@example.test"]);
    }

    private function onlyMessage(string $name): Message
    {
        $messages = $this->messages($name);
        self::assertCount(1, $messages, 'one email, one message');

        return $messages[0];
    }

    /**
     * @return list<array{string, int|null}> folder path and UID, oldest first
     */
    private function locations(Message $message): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT mb.full_path, l.imap_uid FROM message_location l JOIN mailbox mb ON mb.id = l.mailbox_id WHERE l.message_id = ? ORDER BY l.id',
            [$message->id],
        );

        return array_map(static fn (array $row): array => [(string) $row['full_path'], null === $row['imap_uid'] ? null : (int) $row['imap_uid']], $rows);
    }
}
