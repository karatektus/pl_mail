<?php

declare(strict_types=1);

namespace App\Tests\Service\Pop3;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Enum\Account\Pop3DeleteMode;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\Pop3Uidl;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\Pop3UidlRepository;
use App\Service\Imap\MessageSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Pop3\Pop3AccountSyncer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A POP3 sync against a mailbox that behaves like one (#289).
 *
 * Each test is one of the ways the first version of this syncer lost or
 * duplicated mail, stated as the behaviour that must hold instead:
 *
 *  - a message is called stored only once its row is committed, so a message
 *    the server would not send is neither recorded nor ever deleted;
 *  - message numbers are asked for in every session, so a renumbered mailbox
 *    never has the wrong message fetched or deleted under another's UIDL;
 *  - the ledger follows the server rather than a cap, so a mailbox kept on
 *    the server is never fetched twice however large it grows;
 *  - after a switch from IMAP, mail the account already holds is recognised
 *    rather than stored again.
 *
 * FakePop3Server renumbers on QUIT exactly as RFC 1939 says, and refuses a
 * DELE whose number names a different message than the UIDL it is sent for —
 * the stale-number bug would fail there rather than pass by luck.
 */
final class Pop3AccountSyncerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FakePop3Server $server;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user            = new User();
        $user->email     = 'pop3-sync-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $user->nameFirst = 'Pop3';
        $user->nameLast  = 'Sync';
        $user->password  = 'x';
        $this->em->persist($user);

        $account                   = new Account();
        $account->usr              = $user;
        $account->name             = 'POP3 fixture';
        $account->email            = 'pop3-' . bin2hex(random_bytes(4)) . '@plmail.test';
        $account->username         = $account->email;
        $account->incomingProtocol = IncomingProtocol::Pop3;
        $account->imapHost         = 'pop.example.test';
        $account->imapPort         = 995;
        $account->imapEncryption   = 'ssl';
        $account->authType         = 'password';
        $account->isActive         = true;
        $this->em->persist($account);
        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;
        $this->server  = new FakePop3Server();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * New mail lands in a mailbox bound to the Inbox label, and the next sync
     * fetches nothing it already has.
     *
     * The binding is what makes the mail visible: every list is label-driven,
     * and a message filed into an unbound mailbox carries no label at all.
     */
    public function testNewMailIsStoredInTheInboxAndNotFetchedTwice(): void
    {
        $this->server->add('u1', $this->raw('one@example.test', 'One'));
        $this->server->add('u2', $this->raw('two@example.test', 'Two'));

        $this->syncer()->sync($this->account);

        self::assertSame(['One', 'Two'], $this->storedSubjects());

        $mailbox = $this->inbox();
        self::assertSame(LabelRole::Inbox, $mailbox->label?->role, 'the mailbox is bound to the Inbox label');
        self::assertSame(0, $mailbox->importFloorUid, 'a POP3 mailbox has no history walk, so it is never "importing"');

        foreach ($this->messages() as $message) {
            self::assertContains(LabelRole::Inbox, $message->labels->map(static fn ($label) => $label->role)->toArray());
            self::assertNull($message->imapUid);
        }

        $this->syncer()->sync($this->account);

        self::assertCount(2, $this->server->sent('RETR'), 'the second sync fetched nothing');
        self::assertCount(2, $this->messages());
    }

    /**
     * A message the server will not send is not recorded as stored, is asked
     * for again, is given up on after three tries — and is never deleted from
     * the server, because it is not here.
     *
     * This is the failure that lost mail: the first version recorded every
     * UIDL in the batch, and the delete mode then deleted the ones that had
     * failed as well.
     */
    public function testAMessageThatFailedIsNeitherRecordedNorDeleted(): void
    {
        $this->account->pop3DeleteMode = Pop3DeleteMode::Delete;
        $this->em->flush();

        $this->server->add('good', $this->raw('good@example.test', 'Good'));
        $this->server->add('bad', $this->raw('bad@example.test', 'Bad'));
        $this->server->refuse['bad'] = true;

        for ($i = 0; $i < Pop3Uidl::MAX_ATTEMPTS + 2; ++$i) {
            $this->syncer()->sync($this->account);
        }

        self::assertSame(['Good'], $this->storedSubjects());
        self::assertSame(['good'], $this->server->sent('DELE'), 'only what is stored here is deleted there');
        self::assertArrayHasKey('bad', $this->server->messages, 'the failed message is still on the server');
        self::assertCount(Pop3Uidl::MAX_ATTEMPTS, array_filter($this->server->sent('RETR'), static fn (string $u): bool => 'bad' === $u), 'asked for again, then given up on');

        $ledger = $this->ledger();
        self::assertFalse($ledger['bad']['stored']);
        self::assertSame(Pop3Uidl::MAX_ATTEMPTS, $ledger['bad']['attempts']);
    }

    /**
     * A sync that spans sessions asks each one for its own numbering.
     *
     * Another client deletes mail between our sessions, so every number from
     * the first session is off by the time the second runs. The fake refuses
     * a DELE whose number names a different message, and every row must carry
     * the subject of the UIDL it was fetched under.
     */
    public function testEverySessionIsNumberedAfresh(): void
    {
        $this->account->pop3DeleteMode = Pop3DeleteMode::Delete;
        $this->em->flush();

        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $uidl) {
            $this->server->add($uidl, $this->raw($uidl . '@example.test', 'Message ' . $uidl));
        }

        // Fetched newest first, two per session. As the second session opens,
        // the phone has deleted the two oldest — so every number the first
        // session handed out for c and d now names another message.
        $this->server->onOpen = static function (FakePop3Server $server, int $session): void {
            if (2 === $session) {
                unset($server->messages['a'], $server->messages['b']);
            }
        };

        $this->syncer(batchSize: 2)->sync($this->account);

        self::assertGreaterThan(1, $this->server->sessions);
        self::assertSame(['Message c', 'Message d', 'Message e', 'Message f'], $this->storedSubjects(), 'a and b left the server before we reached them');

        foreach ($this->messages() as $message) {
            self::assertSame((string) $message->subject, 'Message ' . explode('@', (string) $message->messageId)[0]);
        }

        self::assertSame([], $this->server->messages, 'everything stored and still listed was deleted, nothing else');
    }

    /**
     * The ledger is as long as the server's mailbox, never a cap.
     *
     * The first version kept the last 5,000 UIDLs. A mailbox kept on the
     * server past that size had its oldest messages fall out of the list,
     * come back as new, and be stored again — on every sync. Here a UIDL the
     * server stops listing is forgotten, and one it still lists never is.
     */
    public function testTheLedgerFollowsTheServerInsteadOfACap(): void
    {
        foreach (['x', 'y', 'z'] as $uidl) {
            $this->server->add($uidl, $this->raw($uidl . '@example.test', strtoupper($uidl)));
        }

        $this->syncer()->sync($this->account);

        // Another client removes two; a new one arrives.
        unset($this->server->messages['x'], $this->server->messages['y']);
        $this->server->add('w', $this->raw('w@example.test', 'W'));

        $this->syncer()->sync($this->account);
        $this->syncer()->sync($this->account);

        self::assertSame(['w', 'z'], $this->sortedKeys($this->ledger()), 'the ledger is exactly what the server lists');
        self::assertSame(['W', 'X', 'Y', 'Z'], $this->storedSubjects(), 'mail gone from the server stays here, and nothing is stored twice');
        self::assertCount(4, $this->server->sent('RETR'));
    }

    /**
     * Under the per-sync limit, the newest mail comes first.
     *
     * A first sync of a large mailbox takes several polls; what somebody is
     * waiting for is what arrived last, which is the end of the server's list.
     */
    public function testTheNewestMailIsFetchedFirst(): void
    {
        foreach (['1', '2', '3', '4', '5'] as $uidl) {
            $this->server->add($uidl, $this->raw('m' . $uidl . '@example.test', 'M' . $uidl));
        }

        $this->syncer(maxPerSync: 2)->sync($this->account);
        self::assertSame(['5', '4'], $this->server->sent('RETR'));

        $this->syncer(maxPerSync: 2)->sync($this->account);
        $this->syncer(maxPerSync: 2)->sync($this->account);

        self::assertSame(['5', '4', '3', '2', '1'], $this->server->sent('RETR'));
        self::assertCount(5, $this->messages());
    }

    /**
     * After a switch from IMAP, mail the account already holds is recognised
     * by its Message-ID and not stored again — read with TOP, so recognising
     * it does not mean downloading it.
     *
     * The pass ends once it has seen the whole listing; after that a repeated
     * Message-ID is new mail like any other.
     */
    public function testAnAdoptingPassRecognisesMailTheAccountHolds(): void
    {
        $this->heldMessage('held@example.test', 'Already here');

        $this->account->pop3Adopting   = true;
        $this->account->pop3DeleteMode = Pop3DeleteMode::Delete;
        $this->em->flush();

        $this->server->add('h', $this->raw('held@example.test', 'Already here'));
        $this->server->add('n', $this->raw('new@example.test', 'New'));

        $this->syncer()->sync($this->account);

        self::assertSame(['Already here', 'New'], $this->storedSubjects(), 'the held message was not stored a second time');
        self::assertSame(['n'], $this->server->sent('RETR'), 'recognised from its headers alone');
        self::assertSame(['n', 'h'], $this->server->sent('TOP'), 'newest first, as always');
        self::assertSame(['h', 'n'], $this->sortedKeys(array_filter($this->ledger(), static fn (array $row): bool => $row['stored'])));
        self::assertSame([], $this->server->messages, 'a recognised message counts as stored, so the delete mode takes it too');
        self::assertFalse($this->refreshedAccount()->pop3Adopting, 'the pass is over once it has seen the whole listing');
    }

    /** A server without TOP still has its messages recognised, over RETR. */
    public function testAnAdoptingPassWorksWithoutTop(): void
    {
        $this->heldMessage('held@example.test', 'Already here');

        $this->account->pop3Adopting = true;
        $this->em->flush();

        $this->server->offersTop = false;
        $this->server->add('h', $this->raw('held@example.test', 'Already here'));

        $this->syncer()->sync($this->account);

        self::assertSame(['Already here'], $this->storedSubjects());
        self::assertTrue($this->ledger()['h']['stored']);
    }

    /**
     * Header parsing for the adoption pass: unfolded, bracketed, and never a
     * DKIM signature's list of signed headers.
     */
    public function testTheMessageIdIsReadOffTheHeaderBlock(): void
    {
        self::assertSame('a@b', Pop3AccountSyncer::messageIdOf("Subject: x\r\nMessage-ID: <a@b>\r\n\r\nbody"));
        self::assertSame('folded@b', Pop3AccountSyncer::messageIdOf("Message-Id:\r\n <folded@b>\r\n\r\n"));
        self::assertSame('c@d', Pop3AccountSyncer::messageIdOf("Message-ID: <c@d> (comment)\r\n\r\n"));
        self::assertSame('', Pop3AccountSyncer::messageIdOf("DKIM-Signature: v=1; h=from:message-id:date\r\n\r\n"));
        self::assertSame('', Pop3AccountSyncer::messageIdOf("Subject: x\r\n\r\nMessage-ID: <in@body>\r\n"));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function syncer(int $batchSize = Pop3AccountSyncer::BATCH_SIZE, int $maxPerSync = Pop3AccountSyncer::MAX_MESSAGES_PER_SYNC): Pop3AccountSyncer
    {
        $container = static::getContainer();

        return new Pop3AccountSyncer(
            $container->get(MessageSyncer::class),
            $container->get(AccountRepository::class),
            $container->get(MailboxRepository::class),
            $container->get(MessageRepository::class),
            $container->get(Pop3UidlRepository::class),
            $container->get(LabelResolver::class),
            $container->get(PostIngestPipeline::class),
            $this->server,
            $this->em,
            $container->get('doctrine'),
            new NullLogger(),
            $batchSize,
            $maxPerSync,
        );
    }

    private function raw(string $messageId, string $subject): string
    {
        return "From: Sender <sender@example.test>\r\n"
            . 'To: ' . $this->account->email . "\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . 'Message-ID: <' . $messageId . ">\r\n"
            . "Date: Fri, 23 Oct 2026 10:00:00 +0000\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . 'Body of ' . $subject . "\r\n";
    }

    /** A message the IMAP sync stored before the switch: in the inbox, with no UID left. */
    private function heldMessage(string $messageId, string $subject): void
    {
        $held                 = new Message();
        $held->account        = $this->account;
        $held->mailbox        = $this->inboxMailbox();
        $held->messageId      = $messageId;
        $held->subject        = $subject;
        $held->fromAddress    = 'sender@example.test';
        $held->receivedAt     = new \DateTimeImmutable();
        $held->syncedAt       = new \DateTimeImmutable();
        $held->hasAttachments = false;
        $this->em->persist($held);
    }

    /** The Inbox mailbox, made the way the syncer makes it, before any sync. */
    private function inboxMailbox(): Mailbox
    {
        $mailbox                 = new Mailbox();
        $mailbox->account        = $this->account;
        $mailbox->name           = 'Inbox';
        $mailbox->fullPath       = 'INBOX';
        $mailbox->specialUse     = MailboxSpecialUse::INBOX;
        $mailbox->isSelectable   = true;
        $mailbox->isSyncEnabled  = true;
        $mailbox->importFloorUid = 0;
        $this->em->persist($mailbox);

        $resolver = static::getContainer()->get(LabelResolver::class);
        $resolver->bindMailbox($resolver->systemLabel(LabelRole::Inbox, $this->account), $mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function inbox(): Mailbox
    {
        $mailbox = static::getContainer()->get(MailboxRepository::class)->findOneBy([
            'account'    => $this->refreshedAccount(),
            'specialUse' => MailboxSpecialUse::INBOX,
        ]);

        self::assertInstanceOf(Mailbox::class, $mailbox);

        return $mailbox;
    }

    /** @return list<Message> */
    private function messages(): array
    {
        return static::getContainer()->get(MessageRepository::class)->findBy(['account' => $this->refreshedAccount()]);
    }

    /** @return list<string> */
    private function storedSubjects(): array
    {
        $subjects = array_map(static fn (Message $message): string => (string) $message->subject, $this->messages());
        sort($subjects);

        return $subjects;
    }

    /** @return array<string, array{stored: bool, attempts: int}> */
    private function ledger(): array
    {
        return static::getContainer()->get(Pop3UidlRepository::class)->ledgerFor($this->account);
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return list<string>
     */
    private function sortedKeys(array $map): array
    {
        $keys = array_map(strval(...), array_keys($map));
        sort($keys);

        return $keys;
    }

    private function refreshedAccount(): Account
    {
        $account = static::getContainer()->get(AccountRepository::class)->find($this->account->id);
        self::assertInstanceOf(Account::class, $account);

        return $this->account = $account;
    }
}
