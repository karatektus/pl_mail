<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Test my filters on this message", end to end: the matching is Postgres's,
 * so this is where it is proved against the real compiler and query — and
 * where it is proved that a test files nothing and shows nothing of anybody
 * else's mail.
 */
final class MessageFilterCheckControllerTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEachFilterSaysWhetherItMatchesAndNothingIsDone(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage($this->user);

        $this->seedRule('CyberLettre', 'CyberLettre');
        $this->seedRule('Invoices', 'invoice');

        $crawler = $client->request('GET', sprintf('/mail/message/%d/filters', $message->id));

        self::assertResponseIsSuccessful();

        $frame = $crawler->filter(sprintf('turbo-frame#message-filters-%d', $message->id));
        self::assertCount(1, $frame);

        $rows = $frame->filter('li')->each(static fn ($li): string => preg_replace('/\s+/', ' ', trim($li->text())));
        self::assertStringContainsString('CyberLettre — matches', $rows[0]);
        self::assertStringContainsString('Invoices — does not match', $rows[array_key_last($rows)]);

        $this->em->clear();
        $reloaded = $this->em->find(Message::class, $message->id);
        self::assertNotNull($reloaded);
        self::assertSame([], $reloaded->labels->toArray(), 'testing a filter applied it');
    }

    public function testAnotherReadersMessageIsRefused(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage($this->seedUser());

        $client->request('GET', sprintf('/mail/message/%d/filters', $message->id));

        self::assertResponseStatusCodeSame(403);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
        $client->loginUser($this->user);

        return $client;
    }

    private function seedRule(string $name, string $subject): void
    {
        $rule             = new MailRule();
        $rule->usr        = $this->user;
        $rule->name       = $name;
        $rule->conditions = ['subject' => $subject];
        $rule->actions    = [['type' => 'star']];

        $this->em->persist($rule);
        $this->em->flush();
    }

    private function seedMessage(User $owner): Message
    {
        $account                 = new Account();
        $account->usr            = $owner;
        $account->name           = 'Filter check fixture';
        $account->email          = 'filter-check@example.test';
        $account->username       = 'filter-check-' . uniqid('', true) . '@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Votre CyberLettre';
        $thread->normalizedSubject = 'votre cyberlettre';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-10-26');
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = 'Votre CyberLettre';
        $message->fromAddress    = 'cyberveille@cyberveille.eu';
        $message->fromName       = 'Cyber Lettre';
        $message->bodyText       = 'La cyberlettre du jour.';
        $message->receivedAt     = new DateTimeImmutable('2026-10-26 09:00');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->messageId      = sprintf('<filter-check-%s@example.test>', uniqid('', true));
        $thread->addMessage($message);
        $this->em->persist($message);

        $this->em->flush();

        return $message;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'filter-check-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Filter';
        $user->nameLast  = 'Check';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
