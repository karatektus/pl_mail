<?php

declare(strict_types=1);

namespace App\Tests\Jmap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Protocol\JmapContext;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One user, one mail account, one IMAP mailbox — the smallest world a JMAP
 * method will run against, inside a transaction that is rolled back.
 *
 * Shared rather than copied into each test because the seed is not the subject
 * of any of them: every JMAP method starts by resolving an accountId against
 * the authenticated user, so a test that gets that wrong fails for a reason
 * that has nothing to do with what it is asking about.
 */
abstract class JmapTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Connection $connection;
    protected LabelResolver $labelResolver;

    protected User $user;
    protected Account $account;
    protected Mailbox $mailbox;

    private int $uid = 7000;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);

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

    protected function accountId(): string
    {
        return (string) $this->account->id;
    }

    protected function context(): JmapContext
    {
        return new JmapContext($this->user);
    }

    /**
     * The binding id of a system label, which is what a JMAP Mailbox id is.
     *
     * Resolving the label materialises it on this account if it was not there
     * already — system labels are created lazily, so an account genuinely has
     * no Spam mailbox until something needs one.
     */
    protected function mailboxIdFor(LabelRole $role): string
    {
        $label = $this->labelResolver->systemLabel($role, $this->account);
        $binding = $this->labelResolver->binding($label, $this->account);
        $this->em->flush();

        return (string) $binding->id;
    }

    /** @return list<string> the roles currently on the message, as strings */
    protected function rolesOn(Message $message): array
    {
        $roles = [];

        foreach ($message->labels as $label) {
            $roles[] = $label->role->value ?? ('label:'.$label->name);
        }

        sort($roles);

        return $roles;
    }

    /**
     * A received message — not a draft — carrying the roles named.
     *
     * @param list<LabelRole> $roles
     */
    protected function receivedMessage(array $roles = [LabelRole::Inbox]): Message
    {
        $message = $this->message();

        foreach ($roles as $role) {
            $message->addLabel($this->labelResolver->systemLabel($role, $this->account));
        }

        $this->em->flush();

        return $message;
    }

    /**
     * A locally-composed draft, built the way persistence expects rather than
     * through JmapDraftWriter, so a test of the writer is not seeded by it.
     */
    protected function draftMessage(): Message
    {
        $message = $this->message();
        $message->addFlag(MessageFlag::DRAFT);
        $message->addLabel($this->labelResolver->systemLabel(LabelRole::Drafts, $this->account));
        $this->em->flush();

        return $message;
    }

    protected function customLabel(string $name, ?Label $parent = null): Label
    {
        $label = new Label();
        $label->usr = $this->user;
        $label->name = $name;
        $label->parent = $parent;
        $this->em->persist($label);
        $this->em->flush();

        $this->labelResolver->binding($label, $this->account);
        $this->em->flush();

        return $label;
    }

    private function message(): Message
    {
        ++$this->uid;

        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'Jmap fixture';
        $thread->normalizedSubject = 'jmap fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->unreadCount = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Jmap fixture';
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('<jmap-%s@example.test>', uniqid('', true));
        $message->mailbox = $this->mailbox;
        $message->imapUid = $this->uid;

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $suffix = uniqid('', true);

        $this->user = new User();
        $this->user->email = 'jmap-'.$suffix.'@example.test';
        $this->user->nameFirst = 'Jmap';
        $this->user->nameLast = 'Fixture';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->user->createdAt = new \DateTimeImmutable();
        $this->user->updatedAt = new \DateTimeImmutable();
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->name = 'Jmap Fixture';
        $this->account->email = 'jmap-'.$suffix.'@example.test';
        $this->account->username = 'jmap-'.$suffix.'@example.test';
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

        // AccountResolver scopes on the inverse side, which persisting the
        // owning side alone does not populate.
        $this->user->addAccount($this->account);
    }
}
