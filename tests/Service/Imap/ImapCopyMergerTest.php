<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Imap\ImapCopyMerger;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Folding copies stored separately before #415, on rows written directly —
 * the cases a sync against a server does not easily produce.
 */
final class ImapCopyMergerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    private Account $account;
    private Mailbox $inbox;
    private Mailbox $archive;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The row kept is the oldest; the JMAP id a client holds is the newer
     * copy's. It is taken over — after that copy is gone, since a JMAP id is
     * unique per account and a flush updates before it deletes.
     */
    public function testTheSurvivorTakesTheIdAJmapClientHolds(): void
    {
        $olderId = $this->message($this->inbox, 11, null)->id;
        $newerId = $this->message($this->archive, 4, 'jmap-e1')->id;

        self::assertSame(1, self::getContainer()->get(ImapCopyMerger::class)->foldKeptIn($this->inbox));
        $this->em->clear();

        $kept = $this->em->find(Message::class, $olderId);
        self::assertInstanceOf(Message::class, $kept);
        self::assertSame('jmap-e1', $kept->jmapId);
        self::assertCount(2, $kept->locations);
        self::assertNull($this->em->find(Message::class, $newerId));
    }

    /**
     * Two messages that share a Message-ID but not a size are two emails, as
     * for new copies: nothing is folded.
     */
    public function testTheSameMessageIdWithAnotherSizeIsLeftAlone(): void
    {
        $this->message($this->inbox, 11, null);
        $this->message($this->archive, 4, null, size: 2048);

        self::assertSame(0, self::getContainer()->get(ImapCopyMerger::class)->foldKeptIn($this->inbox));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function message(Mailbox $mailbox, int $uid, ?string $jmapId, int $size = 1024): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $mailbox;
        $message->imapUid        = $uid;
        $message->messageId      = 'folded@example.test';
        $message->rfc822Size     = $size;
        $message->jmapId         = $jmapId;
        $message->subject        = 'Folded';
        $message->fromAddress    = 'sender@example.test';
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable();
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'merger-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Copy';
        $user->nameLast  = 'Merger';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'merger-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->account = $account;

        $this->inbox   = $this->mailbox('INBOX');
        $this->archive = $this->mailbox('Archive');

        $this->em->flush();
    }

    private function mailbox(string $path): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $path;
        $mailbox->fullPath      = $path;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);

        return $mailbox;
    }
}
