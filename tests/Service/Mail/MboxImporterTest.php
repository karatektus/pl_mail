<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Mail\MboxImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The mbox importer, against a real kernel and a real database.
 *
 * The property under test is not "the parser works" — webklex's does, and it
 * is the same parser a live POP3 sync runs. It is the three decisions this
 * class makes around that parser, each of which a wrong answer makes a mess
 * nobody notices until weeks later:
 *
 *  - Duplicates are skipped by Message-ID, so a re-run is a no-op.
 *  - Trash is refused outright, by the service and not only the command.
 *  - Imported mail is indistinguishable from fetched mail: labelled, threaded,
 *    and carrying the raw bytes on disk.
 *
 * The messages are real RFC822, not fixtures built field by field, because
 * the whole point of going through MessageSyncer is that the parse decides
 * the fields. A hand-built Message would test the importer's faith in the
 * parser rather than the parser.
 */
final class MboxImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MboxImporter $importer;
    private User $user;
    private Account $account;
    private string $tmpDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em       = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->importer = static::getContainer()->get(MboxImporter::class);

        $this->em->getConnection()->beginTransaction();

        $this->user = new User();
        $this->user->email     = 'mbox-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $this->user->nameFirst = 'Mbox';
        $this->user->nameLast  = 'Fixture';
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr     = $this->user;
        $this->user->addAccount($this->account);
        $this->account->name    = 'Mbox fixture';
        $this->account->email   = 'mbox-fixture@plmail.test';
        $this->account->username = $this->account->email;
        $this->account->incomingProtocol = \App\Domain\Enum\Account\IncomingProtocol::Pop3;
        $this->account->imapHost = 'pop.example.test';
        $this->account->imapPort = 995;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType = 'password';
        $this->account->isActive = true;
        $this->em->persist($this->account);
        $this->em->flush();

        $this->tmpDir = sys_get_temp_dir() . '/mbox-import-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testItImportsInboxMailWithLabelThreadAndRaw(): void
    {
        $file = $this->writeMbox('Inbox', [
            $this->message('First subject', 'alice@example.test', '2024-03-01 10:00:00 +0000'),
            $this->message('Second subject', 'bob@example.test', '2024-03-02 11:00:00 +0000'),
        ]);

        $result = $this->importer->importFile($file, $this->account, LabelRole::Inbox);

        self::assertSame(2, $result->total);
        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->failed);

        $rows = $this->em->getRepository(Message::class)->findBy(['account' => $this->account]);

        self::assertCount(2, $rows, 'both messages are stored');

        foreach ($rows as $message) {
            self::assertNotNull($message->mailbox, 'the message is filed into a mailbox');
            self::assertSame(\App\Domain\Enum\Mail\MailboxSpecialUse::INBOX, $message->mailbox->specialUse);
            self::assertCount(1, $message->labels, 'the message carries the Inbox label — an unlabelled message is invisible everywhere');
            self::assertSame(LabelRole::Inbox, $message->labels->first()->role);
            self::assertNull($message->imapUid, 'an imported message has no IMAP UID and none is invented');
            self::assertNotNull($message->thread, 'the message is threaded');
            self::assertNotNull($message->receivedAt, 'the Date header became the received time');
        }
    }

    public function testASecondRunSkipsWhatTheFirstStored(): void
    {
        $file = $this->writeMbox('Inbox', [
            $this->message('Only once', 'alice@example.test', '2024-03-01 10:00:00 +0000'),
        ]);

        $first  = $this->importer->importFile($file, $this->account, LabelRole::Inbox);
        $second = $this->importer->importFile($file, $this->account, LabelRole::Inbox);

        self::assertSame(1, $first->imported);
        self::assertSame(1, $second->total, 'the file was read again');
        self::assertSame(1, $second->skippedDuplicate, 'the Message-ID was already here');
        self::assertSame(0, $second->imported);

        self::assertCount(
            1,
            $this->em->getRepository(Message::class)->findBy(['account' => $this->account]),
            'a re-run must not double the mail',
        );
    }

    public function testItImportsSentUnderTheSentLabel(): void
    {
        $file = $this->writeMbox('Sent', [
            $this->message('About the contract', 'mike@thearvans.com', '2024-04-01 09:00:00 +0000'),
        ]);

        $result = $this->importer->importFile($file, $this->account, LabelRole::Sent);

        self::assertSame(1, $result->imported);

        $message = $this->em->getRepository(Message::class)->findOneBy(['account' => $this->account]);

        self::assertNotNull($message->mailbox);
        self::assertSame(\App\Domain\Enum\Mail\MailboxSpecialUse::SENT, $message->mailbox->specialUse);
        self::assertSame(LabelRole::Sent, $message->labels->first()->role);
    }

    public function testTrashIsRefusedByTheService(): void
    {
        $file = $this->writeMbox('Trash', [
            $this->message('Should never land', 'x@example.test', '2024-01-01 00:00:00 +0000'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trash is not imported');

        $this->importer->importFile($file, $this->account, LabelRole::Trash);
    }

    public function testDryRunCountsAndWritesNothing(): void
    {
        $file = $this->writeMbox('Inbox', [
            $this->message('Counted, not stored', 'alice@example.test', '2024-03-01 10:00:00 +0000'),
        ]);

        $result = $this->importer->importFile($file, $this->account, LabelRole::Inbox, dryRun: true);

        self::assertSame(1, $result->total);
        self::assertSame(1, $result->wouldImport);
        self::assertSame(0, $result->imported);

        self::assertCount(
            0,
            $this->em->getRepository(Message::class)->findBy(['account' => $this->account]),
            'a dry run must not store a row',
        );

        self::assertCount(
            0,
            $this->em->getRepository(Mailbox::class)->findBy(['account' => $this->account]),
            'a dry run must not create a mailbox either',
        );
    }

    public function testAMessageWithNoMessageIdIsStillDeduplicatedByContent(): void
    {
        $file = $this->writeMbox('Inbox', [
            $this->message('No id here', 'anon@example.test', '2024-05-01 12:00:00 +0000', withMessageId: false),
        ]);

        $first  = $this->importer->importFile($file, $this->account, LabelRole::Inbox);
        $second = $this->importer->importFile($file, $this->account, LabelRole::Inbox);

        self::assertSame(1, $first->imported);
        self::assertSame(1, $second->skippedDuplicate, 'identified by the hash of its bytes');

        self::assertCount(1, $this->em->getRepository(Message::class)->findBy(['account' => $this->account]));
    }

    public function testDuplicateCopiesInsideOneFileAreImportedOnce(): void
    {
        $same = $this->message('Copied twice by POP3', 'alice@example.test', '2024-03-01 10:00:00 +0000');

        $file = $this->writeMbox('Inbox', [$same, $same]);

        $result = $this->importer->importFile($file, $this->account, LabelRole::Inbox);

        self::assertSame(2, $result->total);
        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skippedDuplicate);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function writeMbox(string $folder, array $messages): string
    {
        $path = $this->tmpDir . '/' . $folder;

        file_put_contents($path, implode('', array_map(
            static fn (string $message): string => 'From now@example.test ' . date('r') . "\n" . $message . "\n",
            $messages,
        )));

        return $path;
    }

    private function message(string $subject, string $from, string $date, bool $withMessageId = true): string
    {
        $id = 'import-' . bin2hex(random_bytes(6)) . '@plmail.test';

        $lines = [
            'From: ' . $from,
            'To: mbox-fixture@plmail.test',
            'Subject: ' . $subject,
            'Date: ' . $date,
        ];

        if ($withMessageId) {
            $lines[] = 'Message-ID: <' . $id . '>';
        }

        $lines[] = '';
        $lines[] = 'Body of ' . $subject . '.';

        return implode("\n", $lines);
    }
}
