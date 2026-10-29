<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageLocation;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailifyLinks;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The readers that ask "what is in this folder" read locations, and give the
 * right answer for an email kept in more than one (#415).
 *
 * Nothing writes a second location yet — the sync does that in a later step —
 * so each case adds one by hand, which is the shape Proton Bridge's All Mail
 * gives an email: one message, a copy in INBOX and a copy in All Mail. Every
 * assertion is one a reader still on message.mailbox_id would fail: the second
 * copy's UID is invisible to it, and a join would count the email twice.
 */
final class MessageLocationReadersTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageRepository $messages;

    private Account $account;
    private Mailbox $inbox;
    private Mailbox $allMail;
    private Message $message;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->messages   = self::getContainer()->get(MessageRepository::class);

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

    public function testEachFolderListsTheUidItsCopyHas(): void
    {
        self::assertSame([11], array_map(intval(...), $this->messages->findSyncedUids($this->inbox)));
        self::assertSame([905], array_map(intval(...), $this->messages->findSyncedUids($this->allMail)));
        self::assertSame([], $this->messages->findSyncedUids($this->allMail, 905), 'still bounded below');
    }

    public function testTheMessageIsFoundThroughEitherCopy(): void
    {
        self::assertSame($this->message->id, $this->messages->findOneInMailboxByUid($this->inbox, 11)?->id);
        self::assertSame($this->message->id, $this->messages->findOneInMailboxByUid($this->allMail, 905)?->id);
        self::assertNull($this->messages->findOneInMailboxByUid($this->allMail, 11), 'a UID means something only in its own folder');
    }

    public function testTheFlagStateOfAFolderIsKeyedByThatFoldersUid(): void
    {
        self::assertSame([905], array_keys($this->messages->findFlagStateByUid($this->allMail)));
        self::assertSame($this->message->id, $this->messages->findFlagStateByUid($this->allMail)[905]['id']);
    }

    public function testTheSweepNamesTheMessageByThatFoldersUid(): void
    {
        self::assertSame([$this->message->id => 905], $this->messages->findLocatedUidsById($this->allMail));
    }

    /**
     * Folder deletion asks this, and the answer must count the email that the
     * folder's removal would cost, once.
     */
    public function testAFolderCountsTheMessageItKeeps(): void
    {
        $this->location($this->allMail, 906);

        self::assertSame(1, $this->messages->countInMailbox($this->inbox));
        self::assertSame(1, $this->messages->countInMailbox($this->allMail), 'two copies in one folder are still one message');
    }

    public function testTheAccountOwnsTheMessageOnce(): void
    {
        $addresses = iterator_to_array($this->messages->iterateAddressesForAccount($this->account), false);

        self::assertCount(1, $addresses, 'one address set, not one per copy');
        self::assertSame($this->message->id, $this->messages->findOneForAccountByMessageId($this->account, '<kept-twice@example.test>')?->id);
    }

    public function testTheSyncOverviewCountsTheMessageOnce(): void
    {
        $rows = array_values(array_filter(
            self::getContainer()->get(AccountRepository::class)->findSyncOverviewRows(),
            fn (array $row): bool => (int) $row['id'] === $this->account->id,
        ));

        self::assertSame(1, (int) $rows[0]['messages']);
    }

    /**
     * Gmailify, then the switch turned off: a Gmail mailbox carried this
     * account's own IMAP copy, and severing takes the Gmail identity off it.
     * The copy is the IMAP server's throughout, so its folders stay its
     * locations — and it is found to be severed by having one, however many.
     */
    public function testSeveringGmailifyLeavesTheCopiesWhereTheyAre(): void
    {
        $carrier = $this->account($this->account->usr, 'carrier');
        self::assertNotSame($this->account->id, $carrier->id);

        $this->message->gmailCarrierAccount = $carrier;
        $this->message->gmailId             = 'gmail-kept-twice';
        $this->em->flush();

        self::assertSame(1, $this->messages->countImapCopiesCarried((int) $carrier->id), 'carried once, not once per copy');

        self::assertSame(1, self::getContainer()->get(GmailifyLinks::class)->severCarriedBy((int) $carrier->id));

        $this->em->clear();

        self::assertSame(0, $this->messages->countImapCopiesCarried((int) $carrier->id));
        self::assertSame([11], array_map(intval(...), $this->messages->findSyncedUids($this->em->find(Mailbox::class, $this->inbox->id))));
        self::assertSame([905], array_map(intval(...), $this->messages->findSyncedUids($this->em->find(Mailbox::class, $this->allMail->id))));
        self::assertNull($this->em->find(Message::class, $this->message->id)?->gmailId);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * A copy the mirror does not know about: added straight to the collection,
     * as the sync will once it adds locations itself.
     */
    private function location(Mailbox $mailbox, int $uid): void
    {
        $this->message->locations->add(new MessageLocation($this->message, $mailbox, $uid));
        $this->em->flush();
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'readers-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Location';
        $user->nameLast  = 'Readers';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $this->account = $this->account($user, 'readers');

        $this->inbox   = $this->mailbox('INBOX');
        $this->allMail = $this->mailbox('All Mail');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->inbox;
        $message->imapUid        = 11;
        $message->messageId      = '<kept-twice@example.test>';
        $message->subject        = 'Kept twice';
        $message->fromAddress    = 'sender@example.test';
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable();
        $this->em->persist($message);
        $this->em->flush();
        $this->message = $message;

        $this->location($this->allMail, 905);
    }

    private function account(User $user, string $prefix): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $prefix . '-' . uniqid('', true) . '@example.test';
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
        $this->em->flush();

        return $account;
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
