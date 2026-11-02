<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ClassificationHold;
use App\Service\Mail\InitialImportState;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MessageCategorizer;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\RawMessageResolver;
use App\Service\Mail\ThreadStatusUpdater;
use App\Service\Rule\MailRuleEngine;
use App\Service\Rule\RuleActionExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A reply a filter files as spam leaves its conversation where it was, and
 * read (#470).
 *
 * The threader used to move the conversation the moment the reply joined it —
 * sort key forward, new-mail marker reset — and the rules only ran after that.
 * By the time the spam filter had taken the reply out of the Inbox, the
 * conversation it joined was already at the top of the Inbox and marked new,
 * held there by its older mail, with nothing but spam to show for it.
 *
 * Through the real pipeline and rule engine, against the database, because the
 * bug is the order of threading and rules.
 */
final class RuleSpamReplyTest extends KernelTestCase
{
    private const string PARENT_AT = '2026-10-01 09:00:00';
    private const string REPLY_AT  = '2026-10-02 09:00:00';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
        $this->mailbox = $this->seedMailbox();
        $this->seedSpamRule();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASpamFilteredReplyDoesNotMoveItsInboxConversation(): void
    {
        $parent = $this->seedListedConversation('colleague@example.test');

        $reply = $this->seedMessage('reply', 'spammer@example.test', self::REPLY_AT, inReplyTo: $parent->messageId);
        $this->ingest($reply);

        self::assertSame($this->storedThreadOf($parent), $this->storedThreadOf($reply), 'the fixture needs the reply in its parent\'s thread');
        self::assertTrue($this->messageHas($reply, LabelRole::Spam), 'the fixture needs the filter to have filed the reply as spam');
        self::assertSame(self::PARENT_AT, $this->column($parent, 'last_message_at'), 'spam moved the conversation up the Inbox');
        self::assertNotNull($this->column($parent, 'listed_at'), 'spam made the conversation new again');
        self::assertSame('0', $this->column($parent, 'unread_count'), 'the spam reply made the Inbox conversation unread');
    }

    /**
     * Marking the conversation's other mail unread and read again recounts it,
     * and the recount must leave the spam out as the arrival did.
     */
    public function testARecountLeavesTheSpamReplyOut(): void
    {
        $parent = $this->seedListedConversation('colleague@example.test');

        $reply = $this->seedMessage('reply', 'spammer@example.test', self::REPLY_AT, inReplyTo: $parent->messageId);
        $this->ingest($reply);
        $this->em->clear();

        $status = self::getContainer()->get(ThreadStatusUpdater::class);
        $stored = $this->em->find(Message::class, $parent->id);

        self::assertNotNull($stored);

        $status->markRead([$stored], false);
        self::assertSame('1', $this->column($parent, 'unread_count'), 'the parent marked unread counts');

        $status->markRead([$stored], true);
        self::assertSame('0', $this->column($parent, 'unread_count'), 'the recount brought the spam reply back');
    }

    /** Presence: a reply nobody filtered moves the conversation as it always did. */
    public function testAnOrdinaryReplyStillMovesItsConversation(): void
    {
        $parent = $this->seedListedConversation('colleague@example.test');

        $reply = $this->seedMessage('reply', 'other-colleague@example.test', self::REPLY_AT, inReplyTo: $parent->messageId);
        $this->ingest($reply);

        self::assertSame($this->storedThreadOf($parent), $this->storedThreadOf($reply), 'the fixture needs the reply in its parent\'s thread');
        self::assertSame(self::REPLY_AT, $this->column($parent, 'last_message_at'));
        self::assertNull($this->column($parent, 'listed_at'), 'unread mail joining a conversation makes it new again');
        self::assertSame('1', $this->column($parent, 'unread_count'));
    }

    /** A conversation of spam only keeps its own order in the Spam view. */
    public function testASpamReplyStillMovesAConversationOfSpamOnly(): void
    {
        $parent = $this->seedListedConversation('spammer@example.test');

        self::assertTrue($this->messageHas($parent, LabelRole::Spam), 'the fixture needs a conversation the filter has filed');

        $reply = $this->seedMessage('reply', 'spammer@example.test', self::REPLY_AT, inReplyTo: $parent->messageId);
        $this->ingest($reply);

        self::assertSame($this->storedThreadOf($parent), $this->storedThreadOf($reply), 'the fixture needs the reply in its parent\'s thread');
        self::assertSame(self::REPLY_AT, $this->column($parent, 'last_message_at'));
        self::assertSame('1', $this->column($parent, 'unread_count'), 'a conversation of spam only counts its unread spam');
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * A read message from $from, ingested, whose conversation somebody has
     * since seen in the list — so a reset new-mail marker is the reply's doing.
     */
    private function seedListedConversation(string $from): Message
    {
        $parent         = $this->seedMessage('parent', $from, self::PARENT_AT);
        $parent->seenAt = new \DateTimeImmutable(self::PARENT_AT);
        $this->em->flush();

        $this->ingest($parent);

        $this->connection->executeStatement(
            'UPDATE message_thread SET listed_at = NOW() WHERE id = ?',
            [$this->storedThreadOf($parent)],
        );

        $this->em->clear();

        $this->account = $this->em->find(Account::class, $this->account->id);
        $this->mailbox = $this->em->find(Mailbox::class, $this->mailbox->id);

        return $parent;
    }

    /**
     * Built by hand for the reason PostIngestPipelineTest gives: the real
     * service takes its steps from a tagged iterator, and none are wanted here.
     */
    private function ingest(Message $message): void
    {
        $container = self::getContainer();

        $pipeline = new PostIngestPipeline(
            $container->get(ContactRepository::class),
            $container->get(MailBodySanitizer::class),
            $container->get(RawMessageResolver::class),
            $container->get(MessageCategorizer::class),
            $container->get(InitialImportState::class),
            $container->get(ClassificationHold::class),
            $container->get(MessageThreader::class),
            $container->get(MailRuleEngine::class),
            $container->get(MailChangeRecorder::class),
            $this->em,
            $container->get(LoggerInterface::class),
            [],
        );

        $pipeline->run($this->account, [new IngestedMessage($message, $this->account)]);
    }

    private function messageHas(Message $message, LabelRole $role): bool
    {
        return 0 < (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_label ml JOIN label l ON l.id = ml.label_id WHERE ml.message_id = ? AND l.role = ?',
            [$message->id, $role->value],
        );
    }

    /** Read from the table: what the unit of work holds is not the claim. */
    private function column(Message $message, string $column): ?string
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT %s FROM message_thread WHERE id = ?', $column),
            [$this->storedThreadOf($message)],
        );

        self::assertNotFalse($value);

        return null === $value ? null : (string) $value;
    }

    private function storedThreadOf(Message $message): int
    {
        $threadId = $this->connection->fetchOne('SELECT thread_id FROM message WHERE id = ?', [$message->id]);

        self::assertNotFalse($threadId);
        self::assertNotNull($threadId, 'the message was not threaded');

        return (int) $threadId;
    }

    /**
     * In the Inbox, as a sync files mail it found there, and persisted and
     * flushed, which is the pipeline's stated precondition.
     */
    private function seedMessage(string $slug, string $from, string $receivedAt, ?string $inReplyTo = null): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->mailbox;
        $message->subject        = null === $inReplyTo ? 'Quarterly numbers' : 'Re: Quarterly numbers';
        $message->fromAddress    = $from;
        $message->receivedAt     = new \DateTimeImmutable($receivedAt);
        $message->hasAttachments = false;
        $message->bodyHtml       = '<p>hello</p>';
        $message->messageId      = sprintf('rule-spam-%s-%s@example.test', $slug, uniqid('', true));
        $message->inReplyTo      = null === $inReplyTo ? [] : [$inReplyTo];
        $message->references     = $message->inReplyTo;

        $message->addLabel(self::getContainer()->get(LabelResolver::class)->systemLabel(LabelRole::Inbox, $this->account));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** The filter the spam button makes — see SpamFilterCreator. */
    private function seedSpamRule(): void
    {
        $rule             = new MailRule();
        $rule->usr        = $this->account->usr;
        $rule->name       = 'Spam from spammer@example.test';
        $rule->conditions = ['operator' => 'AND', 'conditions' => [['fromAddress' => 'spammer@example.test']]];
        $rule->actions    = [['type' => RuleActionExecutor::MARK_SPAM]];
        $this->em->persist($rule);
        $this->em->flush();
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'rule-spam-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rule';
        $user->nameLast  = 'Spam';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'rule-spam-fixture-' . uniqid('', true) . '@example.test';

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $email;
        $account->username       = $email;
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

    private function seedMailbox(): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
