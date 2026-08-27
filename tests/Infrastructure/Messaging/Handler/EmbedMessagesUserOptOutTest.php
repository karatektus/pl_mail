<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\EmbedMessagesHandler;
use App\Infrastructure\Messaging\Message\EmbedMessagesMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiCallRecorder;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\EmbeddingStore;
use App\Service\Ai\MessageEmbedder;
use App\Service\Ai\OllamaClient;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The worker that indexes mail, and the two ways it can fail to know whose mail
 * it is holding.
 *
 * THIS HANDLER COULD NOT ANSWER THE QUESTION AT ALL until the envelope grew a
 * user id. It reads a row off a transport in a process that has no request, no
 * session and no user — so "may this mailbox be indexed" was unanswerable, and
 * the handler simply indexed.
 *
 * Two cases, and they are different facts:
 *
 *  · A NULL id is an envelope serialised by the previous build, sitting on the
 *    transport across a deployment. It is refused rather than assumed, and the
 *    ids come back round on the next nightly app:ai:index-new-mail.
 *  · An id that resolves to somebody who has switched search off is refused
 *    because they said so.
 *
 * Both are asserted by counting HTTP calls, because that is the only thing that
 * distinguishes "refused" from "tried and stored nothing".
 */
final class EmbedMessagesUserOptOutTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private User $user;

    /** @var list<int> */
    private array $messageIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $this->enableSemanticSearch();
        $this->seedMailbox(3);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The ordinary case, so the refusals below mean something. */
    public function testAnOrdinaryBatchIsEmbedded(): void
    {
        $calls = 0;

        $this->handler($calls)(new EmbedMessagesMessage($this->messageIds, (int) $this->user->id));

        self::assertSame(count($this->messageIds), $calls);
    }

    /**
     * An envelope from before anybody could say no is refused, not embedded.
     *
     * The alternative — treating a missing id as "carry on" — is a worker
     * deciding on somebody's behalf because it could not find out, which is the
     * one thing a job running unattended over a mailbox must not do.
     */
    public function testAnEnvelopeWithNoUserIdIsRefused(): void
    {
        $calls = 0;

        $this->handler($calls)(new EmbedMessagesMessage($this->messageIds));

        self::assertSame(0, $calls, 'an envelope that named nobody was embedded anyway');
    }

    /** A mailbox whose owner has switched search off is refused. */
    public function testAnOptedOutMailboxIsRefused(): void
    {
        $this->user->aiPreferences->searchOff = true;
        $this->em->flush();

        $calls = 0;

        $this->handler($calls)(new EmbedMessagesMessage($this->messageIds, (int) $this->user->id));

        self::assertSame(0, $calls);
    }

    /** A user id that no longer resolves is the same answer as no id at all. */
    public function testAnEnvelopeNamingAMailboxThatIsGoneIsRefused(): void
    {
        $calls = 0;

        $this->handler($calls)(new EmbedMessagesMessage($this->messageIds, 0));

        self::assertSame(0, $calls);
    }

    /**
     * The handler under test, over an HTTP client that counts and answers with
     * a vector.
     *
     * The real EmbedMessagesHandler and the real MessageEmbedder rather than
     * fakes, so a change to either constructor breaks here, where it is cheap.
     */
    private function handler(int &$calls): EmbedMessagesHandler
    {
        $container = self::getContainer();

        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(['embeddings' => [[0.6, 0.8]]]));
        });

        $ai = new AiAssistant(
            $container->get(AiSettingsRepository::class),
            new OllamaClient($http, new NullLogger()),
            new AiCallRecorder($this->createStub(Connection::class), new NullLogger()),
            new NullLogger(),
        );

        $permissions = new AiPermissions($ai);

        $embedder = new MessageEmbedder(
            $ai,
            $permissions,
            $container->get(EmbeddingStore::class),
            $container->get(AiSettingsRepository::class),
            $this->em,
        );

        return new EmbedMessagesHandler(
            $container->get(MessageRepository::class),
            $container->get(UserRepository::class),
            $embedder,
            $container->get(EmbeddingStore::class),
            $permissions,
            $container->get(AiSettingsRepository::class),
        );
    }

    /** The three conditions AiSettings insists on, and the search switch. */
    private function enableSemanticSearch(): void
    {
        $settings = new AiSettings();
        $settings->isEnabled      = true;
        $settings->baseUrl        = 'http://model-host.invalid:11434';
        $settings->embeddingModel = 'qwen3-embedding:0.6b';
        $settings->searchEnabled  = true;

        $this->em->persist($settings);
        $this->em->flush();
    }

    /** A mailbox of its own, threaded, the way EmbeddingCatchUpTest seeds one. */
    private function seedMailbox(int $messages): void
    {
        $user = new User();
        $user->email     = 'embed-optout-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Embed';
        $user->nameLast  = 'OptOut';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'embed-optout-fixture@example.test';
        $account->username       = 'embed-optout-fixture@example.test';
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

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Opt-out fixture';
        $thread->normalizedSubject = 'opt-out fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $this->em->persist($thread);

        $created = [];

        for ($i = 0; $i < $messages; $i++) {
            $message = new Message();
            $message->account        = $account;
            $message->thread         = $thread;
            $message->subject        = sprintf('Opt-out fixture %d', $i);
            $message->bodyText       = 'Something worth describing to a model.';
            $message->fromAddress    = 'sender@example.test';
            $message->messageId      = sprintf('embed-optout-%s-%d@example.test', uniqid('', true), $i);
            $message->receivedAt     = new DateTimeImmutable();
            $message->sentAt         = $message->receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $this->em->persist($message);

            $created[] = $message;
        }

        $this->em->flush();

        $this->user       = $user;
        $this->messageIds = array_map(static fn (Message $m): int => (int) $m->id, $created);
    }
}
