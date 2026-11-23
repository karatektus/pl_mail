<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\DTO\Ai\StoredTranslation;
use App\Domain\Enum\Ai\TranslationLanguage;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Ai\MessageTranslation\MessageTranslationRepository;
use App\Service\Ai\TranslationStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ways a stored translation stops being usable (#713), ThreadSummaryStoreTest's
 * list for one message: another model or another prompt make a row INVISIBLE,
 * a changed text makes it STALE, and a translation into another language is
 * another row. Inside one transaction that is rolled back.
 */
final class TranslationStoreTest extends KernelTestCase
{
    private const string MODEL        = 'qwen3:30b';
    private const string PROMPT       = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string OTHER_PROMPT = '0f9e8d7c6b5a493827160f9e8d7c6b5a493827160f9e8d7c6b5a493827160f9e';

    private Connection $connection;
    private EntityManagerInterface $em;
    private TranslationStore $store;
    private int $messageId;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->store      = new TranslationStore(new MessageTranslationRepository($this->connection), new NullLogger());

        $this->connection->beginTransaction();

        $this->messageId = $this->seedMessage();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAMessageWithNoTranslationHasNone(): void
    {
        self::assertNull($this->read(TranslationLanguage::Russian, 'hash-one'));
    }

    public function testTheStoredTextComesBackWithItsLanguageAndFreshness(): void
    {
        $this->store->save($this->messageId, TranslationLanguage::Russian, 'Пришлите счёт до четверга.', 'hash-one', self::MODEL, self::PROMPT);

        $stored = $this->read(TranslationLanguage::Russian, 'hash-one');

        self::assertNotNull($stored);
        self::assertSame('Пришлите счёт до четверга.', $stored->text);
        self::assertSame(TranslationLanguage::Russian, $stored->language);
        self::assertTrue($stored->isFresh);
        self::assertInstanceOf(DateTimeImmutable::class, $stored->writtenAt);
    }

    public function testATranslationIntoAnotherLanguageIsAnotherRow(): void
    {
        $this->store->save($this->messageId, TranslationLanguage::Russian, 'по-русски', 'hash-one', self::MODEL, self::PROMPT);
        $this->store->save($this->messageId, TranslationLanguage::German, 'auf Deutsch', 'hash-one', self::MODEL, self::PROMPT);

        self::assertSame('по-русски', $this->read(TranslationLanguage::Russian, 'hash-one')?->text);
        self::assertSame('auf Deutsch', $this->read(TranslationLanguage::German, 'hash-one')?->text);
        self::assertNull($this->read(TranslationLanguage::French, 'hash-one'));
    }

    public function testATranslationByAnotherModelOrUnderAnotherPromptIsNotOffered(): void
    {
        $this->store->save($this->messageId, TranslationLanguage::Russian, 'old', 'hash-one', 'llama3.1:8b', self::PROMPT);

        self::assertNull($this->read(TranslationLanguage::Russian, 'hash-one'));

        $this->store->save($this->messageId, TranslationLanguage::Russian, 'new', 'hash-one', self::MODEL, self::PROMPT);

        self::assertNull($this->store->forMessage($this->messageId, TranslationLanguage::Russian, self::MODEL, self::OTHER_PROMPT, static fn (): string => 'hash-one'));
        self::assertSame('new', $this->read(TranslationLanguage::Russian, 'hash-one')?->text);
    }

    public function testAMessageWhoseTextChangedMakesItsTranslationStaleRatherThanAbsent(): void
    {
        $this->store->save($this->messageId, TranslationLanguage::Russian, 'старый текст', 'hash-one', self::MODEL, self::PROMPT);

        $stored = $this->read(TranslationLanguage::Russian, 'hash-two');

        self::assertNotNull($stored, 'a stale translation must still be readable');
        self::assertFalse($stored->isFresh);
    }

    /**
     * The conversation view asks for every message it draws; the text of a
     * body is read only for one that has a row to compare it with.
     */
    public function testTheSourceIsReadOnlyWhenThereIsARow(): void
    {
        $asked = 0;
        $hash  = static function () use (&$asked): string {
            ++$asked;

            return 'hash-one';
        };

        $this->store->forMessage($this->messageId, TranslationLanguage::Russian, self::MODEL, self::PROMPT, $hash);
        self::assertSame(0, $asked);

        $this->store->save($this->messageId, TranslationLanguage::Russian, 'текст', 'hash-one', self::MODEL, self::PROMPT);
        $this->store->forMessage($this->messageId, TranslationLanguage::Russian, self::MODEL, self::PROMPT, $hash);
        self::assertSame(1, $asked);
    }

    public function testTranslatingAgainReplacesTheRow(): void
    {
        self::assertTrue($this->store->save($this->messageId, TranslationLanguage::Russian, 'first', 'hash-one', self::MODEL, self::PROMPT));
        self::assertTrue($this->store->save($this->messageId, TranslationLanguage::Russian, 'second', 'hash-two', self::MODEL, self::PROMPT));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message_translation WHERE message_id = ?', [$this->messageId]));
        self::assertSame('second', $this->read(TranslationLanguage::Russian, 'hash-two')?->text);
    }

    public function testDeletingTheMessageTakesItsTranslationsWithIt(): void
    {
        $this->store->save($this->messageId, TranslationLanguage::Russian, 'текст', 'hash-one', self::MODEL, self::PROMPT);

        $this->connection->executeStatement('DELETE FROM message WHERE id = ?', [$this->messageId]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message_translation WHERE message_id = ?', [$this->messageId]));
    }

    public function testADatabaseThatWillNotAnswerReadsAsNoTranslationAndAFailedWriteIsReported(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('no'));
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('no'));

        $broken = new TranslationStore(new MessageTranslationRepository($connection), new NullLogger());

        self::assertNull($broken->forMessage($this->messageId, TranslationLanguage::Russian, self::MODEL, self::PROMPT, static fn (): string => 'hash-one'));
        self::assertFalse($broken->save($this->messageId, TranslationLanguage::Russian, 'text', 'hash', self::MODEL, self::PROMPT));
    }

    private function read(TranslationLanguage $language, string $hash): ?StoredTranslation
    {
        return $this->store->forMessage($this->messageId, $language, self::MODEL, self::PROMPT, static fn (): string => $hash);
    }

    private function seedMessage(): int
    {
        $user = new User();
        $user->email     = 'translation-store-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Translation';
        $user->nameLast  = 'Store';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'translation-store-fixture@example.test';
        $account->username       = 'translation-store-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Translation store fixture';
        $thread->normalizedSubject = 'translation store fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $this->em->persist($thread);

        $message = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = 'Translation store fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->messageId      = 'translation-store-' . uniqid('', true) . '@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->bodyText       = 'שלום, אנא שלחו את החשבונית עד יום חמישי.';
        $this->em->persist($message);

        $this->em->flush();

        return (int) $message->id;
    }
}
