<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\RecategoriseMessagesHandler;
use App\Infrastructure\Messaging\Message\RecategoriseMessagesMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * A filter's built-in tab is undone with the filter (#762).
 *
 * The filter's choice is the first step of MessageCategorizer, so no re-sort
 * ever undoes it, and a built-in tab cannot be removed the way one of the
 * reader's own can. Deleting the filter, or changing it to file somewhere
 * else, is therefore the one way out — and these are the promises that make
 * it one:
 *
 *  - Deleting the filter clears its tab off the mail it filed, and queues that
 *    mail to be sorted again; sorting it again puts it, and its conversation,
 *    where the cascade says.
 *  - Changing a filter that still files into the same tab — its name, a
 *    condition — keeps its mail where it is. Changing the tab lets it go.
 *  - Another filter's mail is never touched.
 */
final class RuleBuiltinTabReleaseTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testDeletingTheFilterSortsItsMailAgain(): void
    {
        $client  = $this->signIn();
        $rule    = $this->rule('Amazon', MessageCategory::Updates);
        $other   = $this->rule('Shop', MessageCategory::Updates);
        $mine    = $this->filedMessage($rule, MessageCategory::Updates);
        $theirs  = $this->filedMessage($other, MessageCategory::Updates);

        $client->request('POST', '/settings/filters/' . $rule->id . '/delete', [
            '_token' => $this->deleteToken($client, $rule),
        ]);

        self::assertResponseIsSuccessful();

        self::assertSame([null, null], $this->filing($mine), 'the deleted filter\'s tab stayed on its mail');
        self::assertSame(['updates', $other->id], $this->filing($theirs), 'another filter\'s mail was let go');

        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame([(int) $mine->id], $queued[0]->messageIds);
        self::assertSame($this->user->id, $queued[0]->userId);

        // And the job puts the mail, and its conversation, back where the
        // cascade says: nothing on this message says anything but Primary.
        static::getContainer()->get(RecategoriseMessagesHandler::class)($queued[0]);
        $this->em->clear();

        self::assertSame('primary', $this->connection->fetchOne('SELECT category FROM message WHERE id = ?', [$mine->id]));
        self::assertSame('primary', $this->connection->fetchOne('SELECT category FROM message_thread WHERE id = ?', [$mine->thread?->id]));
        self::assertSame('updates', $this->connection->fetchOne('SELECT category FROM message WHERE id = ?', [$theirs->id]));
    }

    public function testAChangeThatStillFilesIntoTheSameTabKeepsTheMail(): void
    {
        $client  = $this->signIn();
        $rule    = $this->rule('Amazon', MessageCategory::Updates);
        $message = $this->filedMessage($rule, MessageCategory::Updates);

        $this->save($client, $rule, 'Amazon, renamed', 'updates');

        self::assertSame(['updates', $rule->id], $this->filing($message));
        self::assertSame([], $this->queued());
    }

    public function testChangingTheTabLetsTheMailGo(): void
    {
        $client  = $this->signIn();
        $rule    = $this->rule('Amazon', MessageCategory::Updates);
        $message = $this->filedMessage($rule, MessageCategory::Updates);

        $this->save($client, $rule, 'Amazon', 'promotions');

        // Let go rather than moved: the new tab is for mail from now on, or
        // for existing mail when the filter is run over it, as with any change.
        self::assertSame([null, null], $this->filing($message));
        self::assertCount(1, $this->queued());
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * This reader's re-sort jobs on the bulk queue — a real Doctrine one in the
     * test environment, listed rather than consumed, and inside this test's
     * transaction like everything else it wrote.
     *
     * @return list<RecategoriseMessagesMessage>
     */
    private function queued(): array
    {
        /** @var ListableReceiverInterface $transport */
        $transport = static::getContainer()->get('messenger.transport.bulk');
        $userId    = $this->user->id;
        $queued    = [];

        foreach ($transport->all() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof RecategoriseMessagesMessage && $message->userId === $userId) {
                $queued[] = $message;
            }
        }

        return $queued;
    }

    /** @return array{0: ?string, 1: ?int} the tab a filter chose, and which filter */
    private function filing(Message $message): array
    {
        /** @var array{rule_builtin_category: ?string, rule_builtin_rule_id: int|string|null} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT rule_builtin_category, rule_builtin_rule_id FROM message WHERE id = ?',
            [$message->id],
        );

        return [
            $row['rule_builtin_category'],
            null === $row['rule_builtin_rule_id'] ? null : (int) $row['rule_builtin_rule_id'],
        ];
    }

    private function save(KernelBrowser $client, MailRule $rule, string $name, string $builtIn): void
    {
        $crawler = $client->request('GET', '/settings/filters/' . $rule->id . '/edit');

        $client->request('POST', '/settings/filters/save', [
            '_token'     => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'id'         => (string) $rule->id,
            'name'       => $name,
            'conditions' => json_encode(['fromDomain' => 'amazon.com'], JSON_THROW_ON_ERROR),
            'actions'    => json_encode([['type' => 'fileInCategory', 'builtIn' => $builtIn]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
    }

    private function deleteToken(KernelBrowser $client, MailRule $rule): string
    {
        $crawler = $client->request('GET', '/settings/filters/list');

        return (string) $crawler
            ->filter('form[action$="/settings/filters/' . $rule->id . '/delete"] input[name="_token"]')
            ->first()
            ->attr('value');
    }

    private function rule(string $name, MessageCategory $tab): MailRule
    {
        $rule             = new MailRule();
        $rule->usr        = $this->user;
        $rule->name       = $name;
        $rule->conditions = ['fromDomain' => 'amazon.com'];
        $rule->actions    = [['type' => 'fileInCategory', 'builtIn' => $tab->value]];
        $rule->sortOrder  = 0;

        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    /** A received message a filter filed into a built-in tab, alone in its conversation. */
    private function filedMessage(MailRule $rule, MessageCategory $tab): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Your order';
        $thread->normalizedSubject = 'your order';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->unreadCount       = 0;
        $thread->category          = $tab;
        $this->em->persist($thread);

        $message                      = new Message();
        $message->account             = $this->account;
        $message->thread              = $thread;
        $message->subject             = 'Your order';
        $message->fromAddress         = 'orders@amazon.com';
        $message->bodyText            = 'Shipped.';
        $message->receivedAt          = new \DateTimeImmutable('-1 hour');
        $message->sentAt              = $message->receivedAt;
        $message->seenAt              = $message->receivedAt;
        $message->flags               = [];
        $message->hasAttachments      = false;
        $message->category            = $tab;
        $message->ruleBuiltinCategory = $tab;
        $message->ruleBuiltinRule     = $rule;

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'builtin-tab-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Builtin';
        $user->nameLast  = 'Tab';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $account              = new Account();
        $account->usr         = $user;
        $account->name        = 'Mail';
        $account->email       = 'builtin-tab-' . uniqid('', true) . '@example.test';
        $account->username    = $account->email;
        $account->authType    = AuthType::Password->value;
        $account->imapHost    = 'imap.example.test';
        $account->mailEnabled = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->user    = $user;
        $this->account = $account;
        $client->loginUser($user);

        return $client;
    }
}
