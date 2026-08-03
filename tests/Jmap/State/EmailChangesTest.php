<?php

declare(strict_types=1);

namespace App\Tests\Jmap\State;

use App\Controller\Mail\ComposeController;
use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Jmap\Method\Mail\EmailChangesMethod;
use App\Jmap\Method\Mail\ThreadChangesMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\MessageSendService;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\MailSenderRegistry;
use App\Service\Mail\MessageCategorizer;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\RawMessageResolver;
use App\Service\Rule\MailRuleEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Delta sync end to end: a write happens, the change log moves, `Email/changes`
 * names the id.
 *
 * Every piece of this had a test except the wiring between them, and the cost of
 * that gap was not a broken feature — it was nobody being able to tell whether
 * the feature was broken. `ThreadChangesMethod`'s docblock spent months claiming
 * Thread changes were never recorded while seven call sites recorded them, and
 * the test seeders wrote mail that `Email/changes` genuinely could not see, so
 * "the phone shows nothing new" had two indistinguishable explanations.
 *
 * So the assertions here deliberately start at a real write rather than at
 * `StateManager` — a recording call that nobody makes passes a unit test of
 * `StateManager` perfectly.
 */
final class EmailChangesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private StateManager $stateManager;
    private EmailChangesMethod $emailChanges;
    private ThreadChangesMethod $threadChanges;
    private ComposeController $compose;

    private User $user;
    private Account $account;
    private Mailbox $mailbox;
    private int $accountId;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->stateManager  = $container->get(StateManager::class);
        $this->emailChanges  = $container->get(EmailChangesMethod::class);
        $this->threadChanges = $container->get(ThreadChangesMethod::class);
        $this->compose       = $container->get(ComposeController::class);

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

    // ── Inbound mail ──────────────────────────────────────────────────────

    /**
     * The ingest path's half of the contract: a row in the log, and a token that
     * is no longer the one the client is holding.
     *
     * Both halves matter separately. A recorded row under an unchanged state is
     * invisible, and a state that moved with nothing behind it makes every
     * client re-ask and find nothing.
     */
    public function testIngestingAMessageRecordsACreateAndAdvancesTheState(): void
    {
        $before = $this->emailState();

        $message = $this->ingest('inbound');

        self::assertGreaterThan($before, $this->emailState());
        self::assertSame(
            ['created'],
            $this->loggedChangeTypes(JmapObjectType::Email, (string) $message->id),
        );
    }

    /** The client's half: it asks with the token it had, and gets the id. */
    public function testEmailChangesReportsAnIngestedMessage(): void
    {
        $sinceState = (string) $this->emailState();

        $message = $this->ingest('reported');

        $result = $this->emailChangesSince($sinceState);

        self::assertSame([(string) $message->id], $result['created']);
        self::assertSame([], $result['updated']);
        self::assertSame([], $result['destroyed']);
        self::assertNotSame($sinceState, $result['newState']);
    }

    /**
     * The conversation moves too, as "updated" rather than "created" — see
     * StateManager::recordThreadsTouched(). This is the fact ThreadChangesMethod
     * used to deny in its own docblock.
     */
    public function testThreadChangesReportsTheThreadTheMessageLandedIn(): void
    {
        $sinceState = (string) $this->stateManager->stateFor($this->accountId, JmapObjectType::Thread);

        $message = $this->ingest('threaded');
        $thread  = $message->thread;

        self::assertNotNull($thread, 'the pipeline threads what it ingests');

        $result = $this->threadChanges->handle(
            ['accountId' => (string) $this->accountId, 'sinceState' => $sinceState],
            new JmapContext($this->user),
        );

        self::assertSame([], $result['created'], 'a new thread arrives in "updated" by design');
        self::assertSame([(string) $thread->id], $result['updated']);
    }

    // ── Web compose ───────────────────────────────────────────────────────

    /**
     * The regression test for mail written in the browser.
     *
     * Every autosave and every send goes through ComposeController::persistDraft
     * and, until this was fixed, none of them recorded anything: a draft written
     * on the desktop and the mail it turned into simply never existed as far as
     * a connected phone was concerned. Nothing about the draft itself is checked
     * here — only that a JMAP client is told it happened.
     */
    public function testAWebComposedDraftIsReportedByEmailChanges(): void
    {
        $sinceState = (string) $this->emailState();

        $draft = $this->composeDraft('Written in the browser');

        $result = $this->emailChangesSince($sinceState);

        self::assertSame([(string) $draft->id], $result['created']);
    }

    /**
     * Autosave fires on every keystroke, so the second save of one draft must be
     * an update. Reporting "created" for an id the client already holds is the
     * one thing RFC 8620 §5.2 says a server must not do.
     */
    public function testResavingADraftReportsAnUpdateRatherThanACreate(): void
    {
        $draft = $this->composeDraft('First keystroke');

        $sinceState = (string) $this->emailState();

        $this->composeDraft('Second keystroke', $draft);

        $result = $this->emailChangesSince($sinceState);

        self::assertSame([], $result['created']);
        self::assertSame([(string) $draft->id], $result['updated']);
    }

    /** A draft's thread moves with it, so the conversation list re-sorts. */
    public function testAWebComposedDraftTouchesItsThread(): void
    {
        $sinceState = (string) $this->stateManager->stateFor($this->accountId, JmapObjectType::Thread);

        $draft  = $this->composeDraft('Threaded in the browser');
        $thread = $draft->thread;

        self::assertNotNull($thread, 'persistDraft threads what it saves');

        $result = $this->threadChanges->handle(
            ['accountId' => (string) $this->accountId, 'sinceState' => $sinceState],
            new JmapContext($this->user),
        );

        self::assertSame([(string) $thread->id], $result['updated']);
    }

    // ── Sending ───────────────────────────────────────────────────────────

    /**
     * The regression test for the draft that never became mail.
     *
     * MessageSendService clears the $draft keyword, swaps the Drafts label for
     * Sent and stamps sentAt, and recorded none of it — so the last thing a
     * phone ever heard about a message was that it was created as a draft, and
     * it kept rendering it as one long after it had been delivered. What is
     * asserted is only the announcement; that the transition itself works is
     * MessageSendService's own business.
     */
    public function testASentMessageIsReportedAsUpdatedByEmailChanges(): void
    {
        $draft = $this->composeDraft('Off it goes');

        $sinceState = (string) $this->emailState();

        self::assertTrue($this->sendService()->send($draft), 'the fake sender accepts everything');
        self::assertNotNull($draft->sentAt, 'the send committed the transition');

        $result = $this->emailChangesSince($sinceState);

        self::assertSame([], $result['created'], 'the client already holds this id from the draft save');
        self::assertSame([(string) $draft->id], $result['updated']);
    }

    /** A conversation with a sent message in it re-sorts and re-summarises. */
    public function testSendingTouchesTheThread(): void
    {
        $draft  = $this->composeDraft('Threaded on its way out');
        $thread = $draft->thread;

        self::assertNotNull($thread, 'persistDraft threads what it saves');

        $sinceState = (string) $this->stateManager->stateFor($this->accountId, JmapObjectType::Thread);

        $this->sendService()->send($draft);

        $result = $this->threadChanges->handle(
            ['accountId' => (string) $this->accountId, 'sinceState' => $sinceState],
            new JmapContext($this->user),
        );

        self::assertSame([(string) $thread->id], $result['updated']);
    }

    // ── The refusal ───────────────────────────────────────────────────────

    /**
     * A token the log has never issued cannot have come from this server — a
     * restored backup, a client bug, a token belonging to another account.
     * Answering "no changes" would be the plausible reply and would leave that
     * client permanently stale, so it is told to resync instead.
     */
    public function testATokenAheadOfTheLogCannotBeAnswered(): void
    {
        $this->ingest('ahead');

        $ahead = (string) ($this->emailState() + 1_000);

        try {
            $this->stateManager->changesSince($this->accountId, JmapObjectType::Email, $ahead);
            self::fail('a token ahead of the log must be refused');
        } catch (MethodException $exception) {
            self::assertSame('cannotCalculateChanges', $exception->toError()['type']);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function emailState(): int
    {
        return (int) $this->stateManager->stateFor($this->accountId, JmapObjectType::Email);
    }

    /**
     * @return array<string,mixed>
     */
    private function emailChangesSince(string $sinceState): array
    {
        return $this->emailChanges->handle(
            ['accountId' => (string) $this->accountId, 'sinceState' => $sinceState],
            new JmapContext($this->user),
        );
    }

    /**
     * Straight out of the table rather than through changesSince(), which
     * collapses created+destroyed pairs away — the question here is what was
     * written, not what a client would make of it.
     *
     * @return list<string>
     */
    private function loggedChangeTypes(JmapObjectType $type, string $entityId): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT change_type FROM jmap_change_log
             WHERE account_id = ? AND object_type = ? AND entity_id = ?
             ORDER BY sequence',
            [$this->accountId, $type->value, $entityId],
        ));
    }

    /** One message the whole way through the real inbound path. */
    private function ingest(string $slug): Message
    {
        $message = new Message();
        $message->account = $this->account;
        $message->mailbox = $this->mailbox;
        $message->subject = 'Changes fixture ' . $slug;
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->bodyHtml = '<p>hello</p>';
        $message->messageId = sprintf('<changes-%s-%s@example.test>', $slug, uniqid('', true));

        // The pipeline's stated precondition: persisted and flushed, ids exist.
        $this->em->persist($message);
        $this->em->flush();

        $this->pipeline()->run($this->account, [new IngestedMessage($message, $this->account)]);

        return $message;
    }

    /**
     * A save through the composer's own private seam.
     *
     * Reflection rather than a request because the route is what is uninteresting
     * here: persistDraft() is the single point every autosave and every send
     * funnels through, and driving it directly keeps the test on the recording
     * rather than on form binding and CSRF.
     */
    private function composeDraft(string $subject, ?Message $message = null): Message
    {
        if (null === $message) {
            $message = new Message();
            $message->account = $this->account;
            $message->createdAt = new \DateTimeImmutable();
            $message->messageId = sprintf('<compose-%s@example.test>', uniqid('', true));
        }

        $message->subject = $subject;
        $message->fromAddress = 'composer@example.test';
        $message->bodyHtml = sprintf('<p>%s</p>', $subject);

        new \ReflectionMethod($this->compose, 'persistDraft')
            ->invoke($this->compose, $message, $this->account);

        return $message;
    }

    /**
     * The real service with a sender that always succeeds.
     *
     * The registry would otherwise resolve SmtpMailSender for this fixture's
     * password account and try to reach localhost:587, and SmtpMailSender
     * swallows the connection failure into `false` — which is indistinguishable
     * from the recording being absent, i.e. exactly the bug being pinned.
     * filesSentCopy() is true so the manual IMAP append is skipped too.
     */
    private function sendService(): MessageSendService
    {
        $container = self::getContainer();

        $sender = new class implements MailSenderInterface {
            public function supports(Account $account): bool
            {
                return true;
            }

            public function send(SymfonyEmail $email, Account $account): bool
            {
                return true;
            }

            public function filesSentCopy(): bool
            {
                return true;
            }
        };

        return new MessageSendService(
            $container->get(MailboxRepository::class),
            $this->em,
            new MailSenderRegistry([$sender]),
            $container->get(ImapConnectionFactory::class),
            $container->get(AttachmentResolver::class),
            $container->get(LabelResolver::class),
            $container->get(LabelRepository::class),
            $this->stateManager,
        );
    }

    /**
     * Built by hand for the same reason PostIngestPipelineTest does it: the real
     * service takes its steps from a tagged iterator, and this test wants none.
     */
    private function pipeline(): PostIngestPipeline
    {
        $container = self::getContainer();

        return new PostIngestPipeline(
            $container->get(ContactRepository::class),
            $container->get(MailBodySanitizer::class),
            $container->get(RawMessageResolver::class),
            $container->get(MessageCategorizer::class),
            $container->get(MessageThreader::class),
            $container->get(MailRuleEngine::class),
            $this->stateManager,
            $this->em,
            $container->get(LoggerInterface::class),
            [],
        );
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'changes-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Email';
        $this->user->nameLast = 'Changes';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        // An address, not a display name: persistDraft() copies this onto
        // the draft's From, and MessageSendService builds a real MIME
        // header out of it further down the same path.
        $this->account->email = 'changes-fixture@example.test';
        $this->account->username = 'changes-fixture@example.test';
        $this->account->imapHost = 'localhost';
        $this->account->imapPort = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost = 'localhost';
        $this->account->smtpPort = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password = 'x';
        $this->account->authType = 'password';
        $this->account->isActive = true;
        $this->em->persist($this->account);

        $this->mailbox = new Mailbox();
        $this->mailbox->account = $this->account;
        $this->mailbox->name = 'INBOX';
        $this->mailbox->fullPath = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->mailbox->createdAt = new \DateTimeImmutable();
        $this->mailbox->updatedAt = new \DateTimeImmutable();
        $this->em->persist($this->mailbox);

        $this->em->flush();

        // getAccounts() is what AccountResolver scopes on, and the inverse side
        // is not populated by persisting the owning side alone.
        $this->user->addAccount($this->account);

        $this->accountId = (int) $this->account->id;
    }
}
