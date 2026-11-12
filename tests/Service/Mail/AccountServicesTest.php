<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AccountService;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Exception\FolderActionRefusedException;
use App\Domain\Exception\SendRefusedException;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\PurgeRemoteMessagesMessage;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\Calendar\CalendarRepository;
use App\Service\Imap\ImapFolderManager;
use App\Service\Imap\MessageSendService;
use App\Service\Mail\AccountServiceSwitch;
use App\Service\Mail\LocalMailPurger;
use App\Service\Mail\UserAccounts;
use App\Tests\Command\MailFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * An account's mail, calendar and contacts, switched on and off on their own
 * (#575) — and the promise that comes with switching mail off: nothing about
 * it reaches the server, and what is already here stays until asked to go.
 *
 * The claims worth guarding are the ones that fail silently. A primary that
 * stays on a mail-off account is a From address nothing can be sent from; a
 * sidebar that hides a mail-off account hides mail somebody may still want;
 * a purge that went through the bin-emptying path would delete the mailbox at
 * the provider; a send or a folder change that slipped past the switch would be
 * exactly the server traffic the person switched off. Each test below names one.
 *
 * Against the real container and database, rolled back, for the reason the
 * rest of the suite gives: the behaviour is what the services do together, and
 * the transports are the in-memory ones the test environment configures, so
 * what would have been queued can be read rather than mocked.
 */
final class AccountServicesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private AccountServiceSwitch $switch;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->switch     = $container->get(AccountServiceSwitch::class);

        foreach (['export', 'ingest', 'maintenance'] as $name) {
            $this->transport($name)->reset();
        }

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the switch ───────────────────────────────────────────────────────────

    public function testSwitchingThePrimarysMailOffMovesThePrimaryToAnAccountThatCanSend(): void
    {
        $user    = MailFixtures::user($this->em, 'svc');
        $primary = MailFixtures::account($this->em, $user);
        $other   = MailFixtures::account($this->em, $user);

        $primary->isPrimary = true;
        $primary->sortOrder = 0;
        $other->sortOrder   = 1;
        $this->em->flush();

        $this->switch->set($primary, AccountService::Mail, false);

        self::assertFalse($primary->isPrimary, 'an account whose mail is off sends nothing');
        self::assertTrue($other->isPrimary);
    }

    public function testSwitchingTheCalendarOnQueuesASyncOfEachMirroredCalendar(): void
    {
        $user                     = MailFixtures::user($this->em, 'svc');
        $account                  = MailFixtures::account($this->em, $user);
        $account->calendarEnabled = false;
        $calendar                 = $this->mirroredCalendar($account);

        $this->switch->set($account, AccountService::Calendar, true);

        $queued = array_map(
            static fn ($envelope): ?int => $envelope->getMessage() instanceof SyncCalendarMessage ? $envelope->getMessage()->calendarId : null,
            $this->transport('ingest')->getSent(),
        );

        self::assertContains($calendar->id, $queued, 'the edits made while it was off go out with this sync');
    }

    public function testACalendarOfAnAccountWithItsCalendarsOffIsNotDueForSync(): void
    {
        $user     = MailFixtures::user($this->em, 'svc');
        $account  = MailFixtures::account($this->em, $user);
        $calendar = $this->mirroredCalendar($account);
        $due      = static fn (array $calendars): array => array_map(static fn (Calendar $each): ?int => $each->id, $calendars);
        $repo     = self::getContainer()->get(CalendarRepository::class);
        $cutoff   = new DateTimeImmutable('+1 year');

        self::assertContains($calendar->id, $due($repo->findDueForSync($cutoff, 10000)));

        $account->calendarEnabled = false;
        $this->em->flush();

        self::assertNotContains($calendar->id, $due($repo->findDueForSync($cutoff, 10000)));
    }

    public function testACalendarOnlyGrantNeedsConsentForMailAndNotForCalendar(): void
    {
        $user    = MailFixtures::user($this->em, 'svc');
        $account = MailFixtures::account($this->em, $user);

        $account->authType           = AuthType::OAuth2->value;
        $account->oauthProvider      = MailProvider::Google->value;
        $account->oauthGrantedScopes = implode(' ', MailProvider::Google->scopesFor([AccountService::Calendar]));
        $account->mailEnabled        = false;
        $this->em->flush();

        self::assertTrue($this->switch->needsConsent($account, AccountService::Mail));
        self::assertFalse($this->switch->needsConsent($account, AccountService::Calendar));
    }

    public function testAPasswordAccountNeverWaitsForConsent(): void
    {
        $account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'svc'));

        foreach (AccountService::cases() as $service) {
            self::assertFalse($this->switch->needsConsent($account, $service));
        }
    }

    // ── what stays visible ───────────────────────────────────────────────────

    public function testTheSidebarKeepsAMailOffAccountOnlyWhileItHoldsMail(): void
    {
        $user     = MailFixtures::user($this->em, 'svc');
        $on       = MailFixtures::account($this->em, $user);
        $holding  = MailFixtures::account($this->em, $user, mailEnabled: false);
        $empty    = MailFixtures::account($this->em, $user, mailEnabled: false);

        $this->threadWithMessage($holding);

        $accounts = self::getContainer()->get(UserAccounts::class);
        $accounts->reset();

        $ids = array_map(static fn (Account $account): ?int => $account->id, $accounts->inMail($user));

        self::assertContains($on->id, $ids);
        self::assertContains($holding->id, $ids, 'mail switched off is mail kept, and reachable');
        self::assertNotContains($empty->id, $ids, 'an account with no mail has no place in a mail sidebar');
    }

    // ── nothing reaches the server ───────────────────────────────────────────

    public function testSendingFromAnAccountWithMailOffIsRefusedWithThatReason(): void
    {
        $account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'svc'), mailEnabled: false);
        $message = MailFixtures::message($this->em, $account);

        try {
            self::getContainer()->get(MessageSendService::class)->send($message);
            self::fail('a send from a mail-off account must not go out');
        } catch (SendRefusedException $e) {
            self::assertSame(SendFailure::MailOff, $e->failure());
        }
    }

    public function testAFolderIsNotMadeOnTheServerWhileMailIsOff(): void
    {
        $account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'svc'), mailEnabled: false);

        try {
            self::getContainer()->get(ImapFolderManager::class)->create($account, null, 'Receipts');
            self::fail('a folder change is a write to the server');
        } catch (FolderActionRefusedException $e) {
            self::assertSame(FolderActionRefusedException::MAIL_OFF, $e->reason);
        }
    }

    // ── removing synced mail ─────────────────────────────────────────────────

    /**
     * With mail ON, deliberately: the button is only shown while mail is off,
     * but a stale page can still send it, and it must stay a local operation
     * then too. The bin-emptying path would have queued a purge on the server.
     */
    public function testRemovingSyncedMailDeletesItHereAndQueuesNothingForTheServer(): void
    {
        $user    = MailFixtures::user($this->em, 'svc');
        $account = MailFixtures::account($this->em, $user);
        $mailbox = MailFixtures::mailbox($this->em, $account);

        $mailbox->uidValidity    = 7;
        $mailbox->lastSeenUid    = 99;
        $account->gmailHistoryId = '12345';
        $this->em->flush();

        [$thread, $message] = $this->threadWithMessage($account);

        $accountId = (int) $account->id;
        $threadId  = (int) $thread->id;
        $messageId = (int) $message->id;
        $mailboxId = (int) $mailbox->id;

        $removed = self::getContainer()->get(LocalMailPurger::class)->purge($account);

        self::assertSame(1, $removed);

        $this->em->clear();

        self::assertNull($this->em->find(MessageThread::class, $threadId));
        self::assertNull($this->em->find(Message::class, $messageId));
        self::assertNotNull($this->em->find(Mailbox::class, $mailboxId), 'folders are structure, and stay');

        foreach ($this->transport('export')->getSent() as $envelope) {
            self::assertNotInstanceOf(PurgeRemoteMessagesMessage::class, $envelope->getMessage());
        }

        self::assertSame([], $this->transport('export')->getSent(), 'nothing at all goes to the server');

        $destroyed = $this->connection->fetchFirstColumn(
            "SELECT object_type || ':' || entity_id FROM jmap_change_log WHERE account_id = ? AND change_type = 'destroyed'",
            [$accountId],
        );

        self::assertContains('Email:' . $messageId, $destroyed, 'the apps are told the message is gone');
        self::assertContains('Thread:' . $threadId, $destroyed);

        // The sync position goes with the mail, so switching mail on reads the
        // mailbox from the start rather than "since" a point now empty.
        $reloaded = $this->em->find(Account::class, $accountId);
        $folder   = $this->em->find(Mailbox::class, $mailboxId);

        self::assertNull($reloaded->gmailHistoryId);
        self::assertNull($folder->uidValidity);
        self::assertSame(0, $folder->lastSeenUid);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . $name);

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function mirroredCalendar(Account $account): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = $account->usr instanceof User ? $account->usr : null;
        $calendar->account  = $account;
        $calendar->name     = 'Work';
        $calendar->role     = CalendarRole::Remote;
        $calendar->remoteId = sprintf('remote-%s', uniqid('', true));

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    /**
     * @return array{MessageThread, Message}
     */
    private function threadWithMessage(Account $account): array
    {
        $thread = new MessageThread();

        $thread->account           = $account;
        $thread->subject           = 'Kept or removed';
        $thread->normalizedSubject = 'kept or removed';
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;

        $this->em->persist($thread);

        $message         = MailFixtures::message($this->em, $account);
        $message->thread = $thread;
        $this->em->flush();

        return [$thread, $message];
    }
}
