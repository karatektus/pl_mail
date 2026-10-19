<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Entity\Label\Label;
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
use App\Service\Rule\MailRuleEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A label a rule puts on arriving mail is on its conversation too.
 *
 * The rules run inside the sync batch, after threading and before the one
 * flush, and a label action re-derives the thread's labels from
 * $thread->messages (ThreadLabelSynchronizer). The threader used to attach a
 * message to its thread on the owning side only, so at that moment the
 * collection did not hold the message the rule had just labelled: a thread
 * opened in this batch had an empty one, and a stored thread loaded its
 * messages from a database where the new row has no thread yet. Either way the
 * label the rule had just added was taken off the conversation again, and the
 * mail was missing from the label's list and the tab a label filter feeds.
 *
 * Through the real pipeline and rule engine, against the database, because the
 * bug is the order of those steps around the flush.
 */
final class RuleLabelOnNewMailTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private Mailbox $mailbox;
    private Label $label;

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
        $this->label   = $this->seedLabel();
        $this->seedRule();

        // A label the account already has a binding for, as it is from the
        // second labelled message on. Creating the binding flushes, and that
        // flush wrote the new message's thread before the label sync ran —
        // which hid the bug for exactly the first message a label ever got.
        $container->get(LabelResolver::class)->binding($this->label, $this->account);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The common case: a first message from somebody opens a conversation. */
    public function testTheLabelIsOnAConversationTheMessageOpens(): void
    {
        $message = $this->seedMessage('opens', null);

        $this->ingest($message);

        self::assertTrue($this->messageHasLabel($message), 'the rule did not label the message');
        self::assertTrue($this->threadHasLabel($message), 'the label was taken off the conversation the message opened');
    }

    /**
     * A reply joining a conversation that is already stored, opened by mail
     * the rule does not match — so the only message carrying the label is the
     * one the rule has just labelled.
     */
    public function testTheLabelIsOnAStoredConversationTheMessageJoins(): void
    {
        $parent = $this->seedMessage('parent', null, from: 'colleague@example.test');

        $this->ingest($parent);
        $this->em->clear();

        $this->account = $this->em->find(Account::class, $this->account->id);
        $this->mailbox = $this->em->find(Mailbox::class, $this->mailbox->id);

        self::assertFalse($this->threadHasLabel($parent), 'the fixture needs a conversation the rule has not labelled');

        $reply = $this->seedMessage('reply', $parent->messageId);

        $this->ingest($reply);

        self::assertSame($this->storedThreadOf($parent), $this->storedThreadOf($reply), 'the fixture needs the reply in its parent\'s thread');
        self::assertTrue($this->threadHasLabel($reply), 'the label was taken off the conversation the message joined');
    }

    // ── fixture ──────────────────────────────────────────────────────────────

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

    private function messageHasLabel(Message $message): bool
    {
        return 0 < (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_label WHERE message_id = ? AND label_id = ?',
            [$message->id, $this->label->id],
        );
    }

    /** Read from the table: what the unit of work holds is not the claim. */
    private function threadHasLabel(Message $message): bool
    {
        return 0 < (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM thread_label WHERE message_thread_id = ? AND label_id = ?',
            [$this->storedThreadOf($message), $this->label->id],
        );
    }

    private function storedThreadOf(Message $message): int
    {
        $threadId = $this->connection->fetchOne('SELECT thread_id FROM message WHERE id = ?', [$message->id]);

        self::assertNotFalse($threadId);
        self::assertNotNull($threadId, 'the message was not threaded');

        return (int) $threadId;
    }

    /** Persisted and flushed, which is the pipeline's stated precondition. */
    private function seedMessage(string $slug, ?string $inReplyTo, string $from = 'billing@example.test'): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->mailbox;
        $message->subject        = null === $inReplyTo ? 'Invoice 2026-10' : 'Re: Invoice 2026-10';
        $message->fromAddress    = $from;
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->bodyHtml       = '<p>hello</p>';
        $message->messageId      = sprintf('rule-label-%s-%s@example.test', $slug, uniqid('', true));
        $message->inReplyTo      = null === $inReplyTo ? [] : [$inReplyTo];
        $message->references     = $message->inReplyTo;

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** Matches mail from billing@, which is every message here but one parent. */
    private function seedRule(): void
    {
        $rule             = new MailRule();
        $rule->usr        = $this->account->usr;
        $rule->name       = 'Label invoices';
        $rule->conditions = ['from' => 'billing@example.test'];
        $rule->actions    = [['type' => 'applyLabel', 'labelId' => $this->label->id]];
        $this->em->persist($rule);
        $this->em->flush();
    }

    private function seedLabel(): Label
    {
        $label            = new Label();
        $label->usr       = $this->account->usr;
        $label->name      = 'Invoices';
        $label->isVisible = true;
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'rule-label-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rule';
        $user->nameLast  = 'Label';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'rule-label-fixture-' . uniqid('', true) . '@example.test';

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
