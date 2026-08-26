<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Ai\EmbeddingStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Storing a vector, and the two ways a stored one can be worse than none.
 *
 * The round trip is the point. Normalisation happens on the way in so the
 * distance function can be a dot product with no square root per row, and a
 * vector that came back denormalised would make every distance subtly wrong in
 * a way no error reports — the search would simply rank badly. So the test
 * reads the value back out of Postgres and checks the length, rather than
 * checking what PHP computed before writing.
 */
final class EmbeddingStoreTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private EmbeddingStore $store;
    private int $messageId;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->store      = new EmbeddingStore($this->connection, new NullLogger());

        $this->connection->beginTransaction();

        // Its own message rather than whatever the database happens to hold:
        // the table has a foreign key to it and the cascade is part of what is
        // being relied on, so the row has to be real — but it must not be
        // somebody else's.
        $this->messageId = $this->seedMessage();
    }

    /** A user, an account and one message, rolled back with the transaction. */
    private function seedMessage(): int
    {
        $user = new User();
        $user->email     = 'embed-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Embed';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'embed-fixture@example.test';
        $account->username       = 'embed-fixture@example.test';
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

        $message = new Message();
        $message->account        = $account;
        $message->subject        = 'Embedding fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->messageId      = 'embed-' . uniqid('', true) . '@example.test';
        $message->receivedAt     = new \DateTimeImmutable();
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $this->em->persist($message);

        $this->em->flush();

        return (int) $message->id;
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAStoredVectorComesBackWithUnitLength(): void
    {
        // Deliberately not unit length going in.
        self::assertTrue($this->store->store($this->messageId, [3.0, 4.0], 'test-model'));

        $length = (float) $this->connection->fetchOne(
            'SELECT sqrt(embedding[1]^2 + embedding[2]^2) FROM message_embedding WHERE message_id = ?',
            [$this->messageId],
        );

        self::assertEqualsWithDelta(1.0, $length, 0.0001, 'the distance function assumes unit length');
    }

    /**
     * The identity the whole search rests on: distance 0 to itself. Computed by
     * Postgres over what was actually stored, so it covers the array literal,
     * float4 precision and the function together.
     */
    public function testAStoredVectorIsAtDistanceZeroFromItself(): void
    {
        $this->store->store($this->messageId, [0.1, -0.9, 0.42, 7.0], 'test-model');

        $distance = (float) $this->connection->fetchOne(
            'SELECT plmail_embed_distance(embedding, embedding) FROM message_embedding WHERE message_id = ?',
            [$this->messageId],
        );

        self::assertEqualsWithDelta(0.0, $distance, 0.0001);
    }

    /** Re-embedding replaces rather than colliding on the primary key. */
    public function testStoringAgainReplacesTheVector(): void
    {
        $this->store->store($this->messageId, [1.0, 0.0], 'old-model');
        $this->store->store($this->messageId, [0.0, 1.0], 'new-model');

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message_embedding WHERE message_id = ?', [$this->messageId]),
        );
        self::assertSame(
            'new-model',
            $this->connection->fetchOne('SELECT model FROM message_embedding WHERE message_id = ?', [$this->messageId]),
        );
    }

    /**
     * A zero vector cannot be normalised, and storing it as NaN would poison
     * every ORDER BY that ever touched the column — attributed to the search
     * rather than to the model that produced it.
     */
    public function testAZeroVectorIsRefused(): void
    {
        self::assertFalse($this->store->store($this->messageId, [0.0, 0.0, 0.0], 'test-model'));
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message_embedding WHERE message_id = ?', [$this->messageId]),
        );
    }

    public function testANonFiniteComponentIsRefused(): void
    {
        self::assertFalse($this->store->store($this->messageId, [1.0, NAN], 'test-model'));
        self::assertFalse($this->store->store($this->messageId, [1.0, INF], 'test-model'));
    }

    /**
     * Changing the model has to make every old row invisible, or a backfill
     * believes it has finished work it never did — which leaves a mailbox
     * permanently half-searchable with nothing to show for it.
     */
    public function testAVectorFromAnotherModelDoesNotCount(): void
    {
        $this->store->store($this->messageId, [1.0, 0.0], 'old-model');

        self::assertSame([$this->messageId], $this->store->alreadyStored([$this->messageId], 'old-model'));
        self::assertSame([], $this->store->alreadyStored([$this->messageId], 'new-model'));
    }

    public function testDeletingTheMessageTakesTheVectorWithIt(): void
    {
        $this->store->store($this->messageId, [1.0, 0.0], 'test-model');

        $this->connection->executeStatement('DELETE FROM message WHERE id = ?', [$this->messageId]);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message_embedding WHERE message_id = ?', [$this->messageId]),
        );
    }
}
