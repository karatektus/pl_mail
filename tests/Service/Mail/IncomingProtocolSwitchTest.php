<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\Pop3UidlRepository;
use App\Service\Mail\IncomingProtocolSwitch;
use App\Service\Mail\MailImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An account switched between IMAP and POP3 keeps its mail and loses only its
 * server addresses (#289).
 *
 * The next sync on the new protocol recognises the rows by Message-ID; these
 * tests pin the state it needs to find for that — every UID gone so nothing
 * IMAP-shaped acts on a POP3 account, every folder planned or finished as the
 * new protocol needs, the UIDL ledger empty, and the adoption pass on only
 * when it is POP3 that is starting. Pop3AccountSyncerTest covers the pass
 * itself; SentCopyReconciler's own tests cover the IMAP side's re-match.
 *
 * And the guards: once an account is POP3, nothing may open IMAP to it — not
 * IDLE, not the importer, not anything that reaches the connection factory.
 */
final class IncomingProtocolSwitchTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Account $account;
    private Mailbox $inbox;
    private Mailbox $archive;
    private Message $message;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user            = new User();
        $user->email     = 'switch-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $user->nameFirst = 'Protocol';
        $user->nameLast  = 'Switch';
        $user->password  = 'x';
        $this->em->persist($user);

        $account           = new Account();
        $account->usr      = $user;
        $account->name     = 'Switching fixture';
        $account->email    = 'switch-' . bin2hex(random_bytes(4)) . '@plmail.test';
        $account->username = $account->email;
        $account->imapHost = 'mail.example.test';
        $account->imapPort = 993;
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);
        $user->addAccount($account);

        $this->inbox   = $this->folder($account, 'INBOX', MailboxSpecialUse::INBOX);
        $this->archive = $this->folder($account, 'Archive', MailboxSpecialUse::ARCHIVE);

        $message                 = new Message();
        $message->account        = $account;
        $message->mailbox        = $this->inbox;
        $message->imapUid        = 41;
        $message->messageId      = 'kept@example.test';
        $message->subject        = 'Kept across the switch';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new \DateTimeImmutable();
        $message->syncedAt       = new \DateTimeImmutable();
        $message->hasAttachments = false;
        $this->em->persist($message);

        $this->em->flush();

        $this->account = $account;
        $this->message = $message;
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testImapToPop3KeepsTheMailAndDropsTheAddresses(): void
    {
        $this->switchTo(IncomingProtocol::Pop3);

        $this->em->refresh($this->message);

        self::assertSame($this->inbox->id, $this->message->mailbox?->id, 'the row stays in the inbox POP3 will file into');
        self::assertNull($this->message->imapUid, 'no UID left for a flag change or a move to act on');

        foreach ([$this->inbox, $this->archive] as $folder) {
            $this->em->refresh($folder);
            self::assertNull($folder->uidValidity);
            self::assertSame(0, $folder->importFloorUid, 'no IMAP import left to show as running');
        }

        self::assertTrue($this->account->pop3Adopting, 'the first POP3 pass recognises what is already here');
        self::assertFalse(static::getContainer()->get(MailImporter::class)->hasWork($this->account));
    }

    /**
     * Back to IMAP: every folder is read like a first one, and the rows are
     * where SentCopyReconciler::claim() looks for them — address-less, under
     * their Message-ID, claimable from the inbox.
     */
    public function testPop3BackToImapLeavesEveryRowForTheImapSyncToReclaim(): void
    {
        $this->switchTo(IncomingProtocol::Pop3);
        static::getContainer()->get(Pop3UidlRepository::class)->recordStored($this->account, ['some-uidl']);

        $this->switchTo(IncomingProtocol::Imap);

        foreach ([$this->inbox, $this->archive] as $folder) {
            $this->em->refresh($folder);
            self::assertNull($folder->importFloorUid, 'unplanned, so the next sync plans it like a first one');
            self::assertSame(0, $folder->lastSeenUid);
        }

        self::assertFalse($this->account->pop3Adopting);
        self::assertSame([], static::getContainer()->get(Pop3UidlRepository::class)->ledgerFor($this->account), 'UIDLs mean nothing to IMAP');

        $claimable = static::getContainer()->get(MessageRepository::class)
            ->findUnlocatedByMessageId($this->account, 'kept@example.test', false);

        self::assertSame($this->message->id, $claimable?->id);
    }

    public function testKeepingTheProtocolChangesNothing(): void
    {
        $this->switchTo(IncomingProtocol::Imap);

        $this->em->refresh($this->message);
        $this->em->refresh($this->inbox);

        self::assertSame(41, $this->message->imapUid);
        self::assertSame(7, $this->inbox->uidValidity);
    }

    /** The last line of defence: whatever reaches for IMAP is turned away. */
    public function testNothingMayOpenImapToAPop3Account(): void
    {
        $this->switchTo(IncomingProtocol::Pop3);

        $this->expectException(\LogicException::class);

        static::getContainer()->get(ImapConnectionFactory::class)->connect($this->account);
    }

    /** IDLE is an IMAP command; a switch keeps the folder's switch, not the supervisor's interest. */
    public function testIdleLeavesAPop3AccountAlone(): void
    {
        $this->inbox->isIdleEnabled = true;
        $this->em->flush();

        $idling = static fn (): array => array_map(
            static fn (Mailbox $mailbox): ?int => $mailbox->id,
            static::getContainer()->get(MailboxRepository::class)->findIdleEnabledAndSyncEnabled(),
        );

        self::assertContains($this->inbox->id, $idling());

        $this->switchTo(IncomingProtocol::Pop3);

        self::assertNotContains($this->inbox->id, $idling());
    }

    private function switchTo(IncomingProtocol $protocol): void
    {
        $from = $this->account->incomingProtocol;

        $this->account->incomingProtocol = $protocol;
        static::getContainer()->get(IncomingProtocolSwitch::class)->switched($this->account, $from);
        $this->em->flush();
    }

    private function folder(Account $account, string $path, MailboxSpecialUse $use): Mailbox
    {
        $mailbox                 = new Mailbox();
        $mailbox->account        = $account;
        $mailbox->name           = $path;
        $mailbox->fullPath       = $path;
        $mailbox->specialUse     = $use;
        $mailbox->isSelectable   = true;
        $mailbox->isSyncEnabled  = true;
        $mailbox->uidValidity    = 7;
        $mailbox->lastSeenUid    = 41;
        $mailbox->importFloorUid = 0;
        $this->em->persist($mailbox);

        return $mailbox;
    }
}
