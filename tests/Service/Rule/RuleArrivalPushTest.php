<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Enum\Mail\ArrivalPlace;
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
use App\Jmap\State\StateManager;
use App\Service\Rule\MailRuleEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A rule gets to read or re-file new mail before the "New mail" push is decided.
 *
 * The arrival used to be recorded in the loop over the ingested messages, ahead
 * of MailRuleEngine, so mail a rule marked read was announced to the browsers
 * all the same, and a notification opened for something that was no longer
 * unread. It is a verdict after the rules now. Through the real pipeline and
 * rule engine, against the database, because the claim is about that order.
 */
final class RuleArrivalPushTest extends KernelTestCase
{
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
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Every way a rule can deal with new mail before anybody sees it: read,
     * filed out of the Inbox, or put where nobody looks for new mail.
     *
     * @return iterable<string, array{string}>
     */
    public static function rulesThatDealWithIt(): iterable
    {
        yield 'mark as read' => ['markRead'];
        yield 'archive'      => ['archive'];
        yield 'trash'        => ['trash'];
        yield 'spam'         => ['markSpam'];
    }

    #[DataProvider('rulesThatDealWithIt')]
    public function testMailARuleDealtWithIsNotAnArrival(string $action): void
    {
        $this->seedRule($action);

        $state = self::getContainer()->get(StateManager::class);
        $state->drainArrivals();

        $this->ingest($this->seedMessage('billing@example.test'));

        self::assertSame([], $state->drainArrivals(), 'a rule dealt with it before anybody saw it arrive');
    }

    /**
     * The presence companion: the same rules, a sender none of them matches.
     */
    #[DataProvider('rulesThatDealWithIt')]
    public function testMailNoRuleMatchesIsStillAnArrival(string $action): void
    {
        $this->seedRule($action);

        $state = self::getContainer()->get(StateManager::class);
        $state->drainArrivals();

        $this->ingest($this->seedMessage('friend@example.test'));

        self::assertSame([(int) $this->account->id], $state->drainArrivals());
    }

    /**
     * Mail read on another device before this sync fetched it arrives \Seen.
     * It is not news to anybody either, rule or no rule.
     */
    public function testMailThatArrivesAlreadyReadIsNotAnArrival(): void
    {
        $state = self::getContainer()->get(StateManager::class);
        $state->drainArrivals();

        $message         = $this->seedMessage('friend@example.test');
        $message->seenAt = new \DateTimeImmutable('-30 seconds');
        $this->em->flush();

        $this->ingest($message);

        self::assertSame([], $state->drainArrivals());
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** @see RuleLabelOnNewMailTest::ingest() for why the pipeline is built by hand. */
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

    /** Persisted and flushed, which is the pipeline's stated precondition. */
    private function seedMessage(string $from): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->mailbox;
        $message->subject        = 'Invoice 2026-10';
        $message->fromAddress    = $from;
        $message->receivedAt     = new \DateTimeImmutable('-1 minute');
        $message->hasAttachments = false;
        $message->bodyHtml       = '<p>hello</p>';
        $message->messageId      = sprintf('rule-arrival-%s@example.test', uniqid('', true));
        $message->inReplyTo      = [];
        $message->references     = [];
        $message->arrivedIn      = ArrivalPlace::Inbox;

        // Labelled the way a sync labels Inbox mail, so archive and trash have
        // an Inbox label to take away.
        $message->addLabel(self::getContainer()->get(LabelResolver::class)->systemLabel(LabelRole::Inbox, $this->account));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedRule(string $action): void
    {
        $rule             = new MailRule();
        $rule->usr        = $this->account->usr;
        $rule->name       = 'Deal with invoices';
        $rule->conditions = ['from' => 'billing@example.test'];
        $rule->actions    = [['type' => $action]];
        $this->em->persist($rule);
        $this->em->flush();
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'rule-arrival-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rule';
        $user->nameLast  = 'Arrival';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'rule-arrival-fixture-' . uniqid('', true) . '@example.test';

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
        $mailbox->isSyncEnabled = false;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
