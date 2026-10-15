<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the database guarantees about a category somebody made themselves.
 *
 * Two promises, and both are enforced by the schema rather than by anything the
 * application remembers to do — which is the only kind that holds when a row is
 * removed by a console command, a cascade from the user, or a restore:
 *
 *  - A category belongs to its owner and goes with them.
 *  - Taking a category away takes the FILING away and nothing else. The mail
 *    and the conversations that were in it stay exactly where they are, with no
 *    category pointer left dangling at a row that no longer exists. A dangling
 *    pointer here would be a thread no tab query can place.
 */
final class InboxCategoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testACategoryIsStoredForItsOwner(): void
    {
        $account = $this->seedAccount();

        $first  = $this->category($account->usr, 'Shipping');
        $second = $this->category($account->usr, 'Receipts');

        $this->em->clear();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT name FROM inbox_category WHERE usr_id = :usr ORDER BY id',
            ['usr' => $account->usr->id],
        );

        self::assertSame([['name' => 'Shipping'], ['name' => 'Receipts']], $rows);
        self::assertNotNull($first->id);
        self::assertNotNull($second->id);
    }

    public function testRemovingTheOwnerRemovesTheirCategories(): void
    {
        // A user with no account: an account's own foreign key to its user is
        // not what this is about, and would refuse the delete before it began.
        $user = $this->seedUser();
        $this->category($user, 'Shipping');
        $userId = (int) $user->id;

        $this->connection->executeStatement('DELETE FROM "user" WHERE id = :id', ['id' => $userId]);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_category WHERE usr_id = :id', ['id' => $userId]),
            'a deleted user left categories behind',
        );
    }

    /**
     * The invariant itself, with its presence companion: the sibling filed under
     * a DIFFERENT category must keep its filing, or "everything was nulled" and
     * "only the right rows were nulled" would look the same.
     */
    public function testRemovingACategoryUnfilesItsMailAndThreadsAndNothingElse(): void
    {
        $account  = $this->seedAccount();
        $doomed   = $this->category($account->usr, 'Shipping');
        $survivor = $this->category($account->usr, 'Receipts');

        $inDoomed   = $this->threadWithMessage($account, 'filed under the doomed category');
        $inSurvivor = $this->threadWithMessage($account, 'filed under the surviving category');

        $inDoomed['message']->ruleCategory   = $doomed;
        $inDoomed['thread']->userCategory    = $doomed;
        $inSurvivor['message']->ruleCategory = $survivor;
        $inSurvivor['thread']->userCategory  = $survivor;
        $this->em->flush();

        $doomedId = (int) $doomed->id;
        $this->connection->executeStatement('DELETE FROM inbox_category WHERE id = :id', ['id' => $doomedId]);

        $messageRows = $this->connection->fetchAllKeyValue(
            'SELECT id, rule_category_id FROM message WHERE id IN (:a, :b)',
            ['a' => $inDoomed['message']->id, 'b' => $inSurvivor['message']->id],
        );
        $threadRows = $this->connection->fetchAllKeyValue(
            'SELECT id, user_category_id FROM message_thread WHERE id IN (:a, :b)',
            ['a' => $inDoomed['thread']->id, 'b' => $inSurvivor['thread']->id],
        );

        self::assertNull($messageRows[$inDoomed['message']->id], 'the message still points at a category that is gone');
        self::assertNull($threadRows[$inDoomed['thread']->id], 'the thread still points at a category that is gone');
        self::assertSame($survivor->id, (int) $messageRows[$inSurvivor['message']->id], 'a sibling message lost its filing');
        self::assertSame($survivor->id, (int) $threadRows[$inSurvivor['thread']->id], 'a sibling thread lost its filing');

        self::assertSame(
            2,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM message WHERE id IN (:a, :b)',
                ['a' => $inDoomed['message']->id, 'b' => $inSurvivor['message']->id],
            ),
            'removing a category removed mail',
        );
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function category(User $user, string $name): InboxCategory
    {
        $category       = new InboxCategory();
        $category->usr  = $user;
        $category->name = $name;
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /** @return array{thread: MessageThread, message: Message} */
    private function threadWithMessage(Account $account, string $subject): array
    {
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = strtolower($subject);
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $account;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<cat-%s@example.test>', uniqid('', true));
        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return ['thread' => $thread, 'message' => $message];
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'cat-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Category';
        $user->nameLast  = 'Owner';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedAccount(): Account
    {
        $user = $this->seedUser();

        $email = 'cat-fixture-' . uniqid('', true) . '@example.test';

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
