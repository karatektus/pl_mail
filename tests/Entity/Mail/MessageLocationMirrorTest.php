<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That Message::$mailbox / $imapUid reach the location table, whoever writes
 * them (#415).
 *
 * The transition rests on this: nothing outside Message writes a location yet,
 * so a write of the pair that did not arrive there would leave the table that
 * the readers are moving to saying something the message columns do not.
 * Each case writes the pair the way the code does today and reads the table
 * back in SQL, past the unit of work, which is where a reader will look.
 */
final class MessageLocationMirrorTest extends KernelTestCase
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

    public function testANewImapMessageGetsItsFolderAsItsOneLocation(): void
    {
        $message = $this->message($this->inbox, 11);

        self::assertSame([[$this->inbox->id, 11]], $this->locationsOf($message));
    }

    /**
     * POP3 takes the same path: one mailbox, no UID.
     */
    public function testAMessageWithAFolderAndNoUidStillHasALocation(): void
    {
        $message = $this->message($this->inbox, null);

        self::assertSame([[$this->inbox->id, null]], $this->locationsOf($message));
    }

    public function testAMessageWithNoFolderHasNoLocation(): void
    {
        $message = $this->message(null, null);

        self::assertSame([], $this->locationsOf($message));
    }

    /**
     * Moved in place rather than replaced, so a location keeps its id across
     * a move — both while the new UID is unknown and once it arrives.
     */
    public function testAMoveChangesTheLocationInPlace(): void
    {
        $message = $this->message($this->inbox, 11);
        $before  = $this->locationIdsOf($message);

        $message->relocateTo($this->archive);
        $this->em->flush();

        self::assertSame([[$this->archive->id, null]], $this->locationsOf($message));

        $message->relocateTo($this->archive, 4);
        $this->em->flush();

        self::assertSame([[$this->archive->id, 4]], $this->locationsOf($message));
        self::assertSame($before, $this->locationIdsOf($message));
    }

    /**
     * The case the in-place change is hardest on: a row read back from the
     * database, whose location is loaded and may have had its changes computed
     * before the message mirrored into it.
     */
    public function testAMoveOfAReloadedMessageReachesTheTable(): void
    {
        $id = $this->message($this->inbox, 11)->id;
        $this->em->clear();

        $message = $this->em->find(Message::class, $id);
        self::assertInstanceOf(Message::class, $message);
        self::assertCount(1, $message->locations);

        $archive = $this->em->find(Mailbox::class, $this->archive->id);
        self::assertInstanceOf(Mailbox::class, $archive);

        $message->relocateTo($archive, 7);
        $this->em->flush();

        self::assertSame([[$this->archive->id, 7]], $this->locationsOf($message));
    }

    public function testLosingTheFolderRemovesTheLocation(): void
    {
        $message = $this->message($this->inbox, 11);

        $message->relocateTo(null);
        $this->em->flush();

        self::assertSame([], $this->locationsOf($message));
    }

    /**
     * Every flush walks every managed message, so one that did not move must
     * not cost a query to find that out.
     */
    public function testAnUnchangedMessageDoesNotLoadItsLocations(): void
    {
        $id = $this->message($this->inbox, 11)->id;
        $this->em->clear();

        $message = $this->em->find(Message::class, $id);
        self::assertInstanceOf(Message::class, $message);

        $message->subject = 'Changed, but not moved';
        $this->em->flush();

        self::assertInstanceOf(PersistentCollection::class, $message->locations);
        self::assertFalse($message->locations->isInitialized());
    }

    /**
     * The one write of the pair by query, which goes past the entity.
     */
    public function testUnlocatingAFolderClearsItsLocationsToo(): void
    {
        $message = $this->message($this->inbox, 11);

        self::getContainer()->get(MessageRepository::class)->unlocateAll($this->inbox);

        self::assertSame([[$this->inbox->id, null]], $this->locationsOf($message));
    }

    /**
     * With a second copy beside it, a move writes the primary's location —
     * found by its address, not by being first — and leaves the other alone.
     */
    public function testAMoveOfThePrimaryLeavesTheOtherCopyAlone(): void
    {
        $allMail                      = $this->mailbox('All Mail');
        $allMail->mirrorsOtherFolders = true;

        $message = $this->message($allMail, 905);
        $message->addLocation($this->inbox, 11);
        $this->em->flush();

        self::assertSame($this->inbox, $message->mailbox, 'a real folder is primary over a mirror');

        $message->relocateTo($this->archive, 4);
        $this->em->flush();

        self::assertSame([[$allMail->id, 905], [$this->archive->id, 4]], $this->locationsOf($message));
    }

    public function testLosingThePrimaryMakesAnotherCopyPrimary(): void
    {
        $message = $this->message($this->inbox, 11);
        $message->addLocation($this->archive, 4);
        $this->em->flush();

        $primary = $message->locationAt($this->inbox, 11);
        self::assertNotNull($primary);

        $message->removeLocation($primary);
        $this->em->flush();

        self::assertSame($this->archive, $message->mailbox);
        self::assertSame(4, $message->imapUid);
        self::assertSame([[$this->archive->id, 4]], $this->locationsOf($message));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function message(?Mailbox $mailbox, ?int $uid): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $mailbox;
        $message->imapUid        = $uid;
        $message->subject        = 'Located';
        $message->fromAddress    = 'sender@example.test';
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable();
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * @return list<array{int, int|null}>
     */
    private function locationsOf(Message $message): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT mailbox_id, imap_uid FROM message_location WHERE message_id = :id ORDER BY id',
            ['id' => $message->id],
        );

        return array_map(
            static fn (array $row): array => [(int) $row['mailbox_id'], null === $row['imap_uid'] ? null : (int) $row['imap_uid']],
            $rows,
        );
    }

    /**
     * @return list<int>
     */
    private function locationIdsOf(Message $message): array
    {
        return array_map(
            intval(...),
            $this->connection->fetchFirstColumn('SELECT id FROM message_location WHERE message_id = :id ORDER BY id', ['id' => $message->id]),
        );
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'location-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Message';
        $user->nameLast  = 'Location';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'location-' . uniqid('', true) . '@example.test';
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
