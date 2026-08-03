<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Mail\EmailPatchApplier;
use App\Jmap\Protocol\Exception\MethodException;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a JMAP client may rewrite, and on what.
 *
 * Two separate rules meet in this class, and both are load-bearing:
 *
 * **Content is editable on a draft and on nothing else.** A received message's
 * body is a record of what arrived; a client able to rewrite it would make the
 * mailbox unfalsifiable — you could no longer tell what a sender actually sent.
 *
 * **mailboxIds are binding ids, not label ids.** message_label stores
 * user-scoped label ids while a JMAP Mailbox id is a per-account binding id.
 * Both are autoincrement ints from different tables, so the two spaces overlap
 * and a mistranslation looks like a client sending nonsense rather than like a
 * translation that was skipped. That bug shipped once already.
 */
final class EmailPatchApplierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;
    private EmailPatchApplier $applier;

    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->applier       = $container->get(EmailPatchApplier::class);

        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
        $this->mailbox = $this->seedMailbox();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Content is draft-only ─────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function contentProperties(): iterable
    {
        yield 'subject'   => ['subject', 'Rewritten'];
        yield 'to'        => ['to', [['email' => 'someone@example.test']]];
        yield 'textBody'  => ['textBody', [['partId' => 't', 'type' => 'text/plain']]];
        yield 'inReplyTo' => ['inReplyTo', ['<a@example.test>']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contentProperties')]
    public function testContentCannotBeRewrittenOnAReceivedMessage(string $property, mixed $value): void
    {
        $message = $this->message(draft: false);
        $before  = $message->subject;

        try {
            $this->applier->apply($this->account, $message, [$property => $value]);
            self::fail(sprintf('"%s" was accepted on a received message', $property));
        } catch (MethodException) {
            // Expected.
        }

        self::assertSame($before, $message->subject, 'the message was mutated before the refusal');
    }

    public function testContentCanBeRewrittenOnADraft(): void
    {
        $message = $this->message(draft: true);

        $this->applier->apply($this->account, $message, ['subject' => 'Rewritten']);

        self::assertSame('Rewritten', $message->subject);
    }

    /**
     * Only the properties the patch names. A composer that sends just the body
     * is as normal as one that sends the whole object, and treating an absent
     * key as "clear this" would silently drop a subject the user had typed.
     */
    public function testAnAbsentPropertyIsLeftAlone(): void
    {
        $message = $this->message(draft: true);
        $message->subject = 'Kept';
        $this->em->flush();

        $this->applier->apply($this->account, $message, ['to' => [['email' => 'x@example.test']]]);

        self::assertSame('Kept', $message->subject);
    }

    /**
     * The properties that say where a message came from. A draft that could
     * rewrite them could forge one, so they are refused even on a draft.
     */
    public function testProvenancePropertiesAreRefusedEvenOnADraft(): void
    {
        $message = $this->message(draft: true);

        $this->expectException(MethodException::class);

        $this->applier->apply($this->account, $message, ['receivedAt' => '2020-01-01T00:00:00Z']);
    }

    // ── Keywords still work ───────────────────────────────────────────────

    /**
     * Read state is carried by seenAt, not by the SEEN flag — note this path
     * differs from the web UI's, which sets both. Asserted on seenAt because
     * that is what this code actually contracts to change.
     */
    public function testSeenKeywordTogglesReadState(): void
    {
        $message = $this->message(draft: false);

        $this->applier->apply($this->account, $message, ['keywords/$seen' => true]);
        self::assertNotNull($message->seenAt);

        $this->applier->apply($this->account, $message, ['keywords/$seen' => null]);
        self::assertNull($message->seenAt);
    }

    public function testFlaggedKeywordTogglesStarred(): void
    {
        $message = $this->message(draft: false);

        $this->applier->apply($this->account, $message, ['keywords/$flagged' => true]);
        self::assertNotNull($message->starredAt);

        $this->applier->apply($this->account, $message, ['keywords/$flagged' => null]);
        self::assertNull($message->starredAt);
    }

    // ── mailboxIds live in the binding id space ───────────────────────────

    /**
     * The regression. Removing one mailbox from a message that has two used to
     * fail, because the mailboxes it was *keeping* were re-emitted as label ids
     * — which the resolution then rejected as "No such Mailbox".
     */
    public function testRemovingOneMailboxKeepsTheOthers(): void
    {
        $message = $this->message(draft: false);

        $inbox   = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);
        $archive = $this->labelResolver->systemLabel(LabelRole::Archive, $this->account);

        $message->addLabel($inbox);
        $message->addLabel($archive);
        $this->em->flush();

        $archiveBindingId = $archive->bindingFor($this->account)?->id;

        self::assertNotNull($archiveBindingId);

        // Drop Archive, keep Inbox. The keep is what used to break.
        $this->applier->apply($this->account, $message, [
            sprintf('mailboxIds/%d', $archiveBindingId) => null,
        ]);

        self::assertTrue($message->labels->contains($inbox), 'the kept mailbox was lost');
        self::assertFalse($message->labels->contains($archive));
    }

    public function testAnUnknownMailboxIdIsRefused(): void
    {
        $message = $this->message(draft: false);

        $this->expectException(MethodException::class);

        $this->applier->apply($this->account, $message, ['mailboxIds/999999' => true]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function message(bool $draft): Message
    {
        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'Patch fixture';
        $thread->normalizedSubject = 'patch fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->unreadCount = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Patch fixture';
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('<patch-%s@example.test>', uniqid('', true));
        $message->mailbox = $this->mailbox;

        if (true === $draft) {
            $message->addFlag(MessageFlag::DRAFT);
        }

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'patch-'.uniqid('', true).'@example.test';
        $user->nameFirst = 'Patch';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $user->createdAt = new \DateTimeImmutable();
        $user->updatedAt = new \DateTimeImmutable();
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Patch Fixture')
            ->setUsername('patch-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account = $this->account;
        $mailbox->name = 'INBOX';
        $mailbox->fullPath = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $mailbox->createdAt = new \DateTimeImmutable();
        $mailbox->updatedAt = new \DateTimeImmutable();

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
