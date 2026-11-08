<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Service\Label\LabelResolver;
use App\Service\Rule\RuleActionExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A "Mark as spam" filter puts the mail in the server's Junk folder, not in
 * its Archive.
 *
 * It used to be archived on the server: the rule's one provider call knew the
 * bin and "anything else", and anything else was archive(). The next sync then
 * found the mail in the Archive folder and filed it there, unread — every
 * message a spam filter had caught, in the archive.
 */
final class RuleSpamMoveTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labels;
    private Account $account;
    private Mailbox $inbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->labels     = $container->get(LabelResolver::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedFolder('INBOX', MailboxSpecialUse::INBOX, LabelRole::Inbox);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASpamFilterMovesTheMailIntoJunk(): void
    {
        $this->seedFolder('Junk', MailboxSpecialUse::JUNK, LabelRole::Spam);
        $message = $this->seedMessage();

        $this->runSpamRule($message);

        self::assertSame([['move', 'Junk']], $this->serverJobsFor($message), 'the filter did not move the mail into Junk on the server');
    }

    public function testASpamFilterLeavesTheMailInSpamOnlyHere(): void
    {
        $this->seedFolder('Junk', MailboxSpecialUse::JUNK, LabelRole::Spam);
        $message = $this->seedMessage();

        $this->runSpamRule($message);

        self::assertTrue($message->hasLabel($this->role(LabelRole::Spam)));
        self::assertFalse($message->hasLabel($this->role(LabelRole::Inbox)), 'spam the filter caught is still in the Inbox');
        self::assertFalse($message->hasLabel($this->role(LabelRole::Archive)), 'spam the filter caught is in the Archive');
    }

    /** The other two "leave the inbox" actions keep going where they went. */
    public function testArchiveAndTrashFiltersAreUnchanged(): void
    {
        $this->seedFolder('Junk', MailboxSpecialUse::JUNK, LabelRole::Spam);
        $this->seedFolder('Trash', MailboxSpecialUse::TRASH, LabelRole::Trash);

        $archived = $this->seedMessage();
        $trashed  = $this->seedMessage();

        $this->runRule($archived, RuleActionExecutor::ARCHIVE);
        $this->runRule($trashed, RuleActionExecutor::TRASH);

        self::assertSame([['archive', null]], $this->serverJobsFor($archived));
        self::assertSame('trash', $this->serverJobsFor($trashed)[0][0] ?? null);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function runSpamRule(Message $message): void
    {
        $this->runRule($message, RuleActionExecutor::MARK_SPAM);
    }

    private function runRule(Message $message, string $action): void
    {
        $rule             = new MailRule();
        $rule->usr        = $this->account->usr;
        $rule->name       = 'Filter ' . $action;
        $rule->conditions = ['operator' => 'AND', 'conditions' => [['fromAddress' => 'spammer@example.test']]];
        $rule->actions    = [['type' => $action]];
        $this->em->persist($rule);
        $this->em->flush();

        self::getContainer()->get(RuleActionExecutor::class)->execute($rule, $message);
    }

    /**
     * Each IMAP job queued for the message: its action and where it goes.
     *
     * @return list<array{string, string|null}>
     */
    private function serverJobsFor(Message $message): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.export');
        $jobs      = [];

        foreach ($transport->getSent() as $envelope) {
            $job = $envelope->getMessage();

            if ($job instanceof ApplyImapFlagsMessage && true === isset($job->messageIds[(int) $message->id])) {
                $jobs[] = [$job->action, $job->destinationPath];
            }
        }

        return $jobs;
    }

    private function role(LabelRole $role): \App\Entity\Label\Label
    {
        return $this->labels->systemLabel($role, $this->account);
    }

    /** In the inbox folder, as a sync files mail it found there. */
    private function seedMessage(): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->inbox;
        $message->imapUid        = random_int(1, 1_000_000);
        $message->subject        = 'You have won';
        $message->fromAddress    = 'spammer@example.test';
        $message->receivedAt     = new \DateTimeImmutable('2026-11-07 09:00:00');
        $message->hasAttachments = false;
        $message->bodyHtml       = '<p>hello</p>';
        $message->messageId      = sprintf('rule-spam-move-%s@example.test', uniqid('', true));
        $message->addLabel($this->role(LabelRole::Inbox));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedFolder(string $name, MailboxSpecialUse $specialUse, LabelRole $role): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        $this->labels->bindMailbox($this->role($role), $mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'rule-spam-move-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rule';
        $user->nameLast  = 'Spam';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'rule-spam-move-fixture-' . uniqid('', true) . '@example.test';

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
}
