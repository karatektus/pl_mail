<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SyncGmailMessageBatchHandler;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\GmailSkippedMessage\GmailSkippedMessageRepository;
use App\Repository\Mail\Message\MessageProviderIdRepository;
use App\Repository\Mail\Message\MessageThreadingRepository;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\SyncNotifier;
use App\Service\Mail\SyncOrigin;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * Gmail's copy of mail sent from plMail is the row plMail already has (#676).
 *
 * A send writes the row; Gmail files its own copy in Sent, and the sync used
 * to build that copy as a second row, because the first had no Gmail id to be
 * found by. Every reply was listed twice, the copy in a conversation of its
 * own. And because the row's thread never learned Gmail's threadId, the first
 * answer to a new mail started a conversation too.
 */
final class GmailSentCopyTest extends KernelTestCase
{
    private const string SENT_ID   = 'sent-1@example.test';
    private const string THREAD_ID = 'gmail-thread-1';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->account = $this->seedAccount($this->seedUser());
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testGmailsSentCopyIsMergedOntoTheRowPlmailSent(): void
    {
        $sent = $this->seedSentRow();

        $this->handle($this->sentCopy());

        $this->em->refresh($sent);

        self::assertSame(1, $this->rowsWithMessageId(self::SENT_ID), 'one row, not the sent one and Gmail\'s copy');
        self::assertSame('gmail-sent-1', $sent->gmailId);
        self::assertSame(self::THREAD_ID, $sent->providerThreadKey);
        self::assertSame(self::THREAD_ID, $sent->thread?->providerThreadKey, 'the thread learns the conversation Gmail filed it in');
    }

    public function testTheAnswerJoinsTheConversationTheSentMailIsIn(): void
    {
        $sent = $this->seedSentRow();

        $this->handle($this->sentCopy());
        $this->handle([
            'id'       => 'gmail-answer-1',
            'threadId' => self::THREAD_ID,
            'labelIds' => ['INBOX', 'UNREAD'],
            'headers'  => [
                'Message-ID'  => '<answer-1@example.test>',
                'In-Reply-To' => '<' . self::SENT_ID . '>',
                'References'  => '<' . self::SENT_ID . '>',
                'Subject'     => 'Re: Flight',
                'From'        => 'friend@example.test',
                'To'          => 'gmail@example.test',
            ],
        ]);

        $this->em->refresh($sent);

        $answer = $this->em->getRepository(Message::class)->findOneBy(['messageId' => 'answer-1@example.test']);

        self::assertNotNull($answer);
        self::assertSame($sent->thread?->id, $answer->thread?->id);
    }

    public function testARowThatWasNotSentFromHereIsNotTakenForTheCopy(): void
    {
        $unsent         = $this->seedSentRow();
        $unsent->sentAt = null;
        $this->em->flush();

        $this->handle($this->sentCopy());

        self::assertSame(2, $this->rowsWithMessageId(self::SENT_ID));
    }

    public function testAThreadThatAlreadyHasAGmailThreadKeepsIt(): void
    {
        $sent = $this->seedSentRow();
        $sent->thread->providerThreadKey = 'gmail-thread-0';
        $this->em->flush();

        $this->handle($this->sentCopy());

        $this->em->refresh($sent);

        self::assertSame(1, $this->rowsWithMessageId(self::SENT_ID));
        self::assertSame('gmail-thread-0', $sent->thread?->providerThreadKey);
    }

    /**
     * An encrypted reply goes out as "...", over SMTP, and Gmail files it in a
     * thread of its own because the subject does not match. The answers follow
     * it there. plMail keeps them in the conversation the reply was written in.
     */
    public function testAnswersInTheThreadGmailFiledTheReplyInJoinTheConversation(): void
    {
        $sent                            = $this->seedSentRow();
        $sent->thread->providerThreadKey = 'gmail-thread-0';
        $this->em->flush();

        $this->handle($this->sentCopy());
        $this->handle([
            'id'       => 'gmail-answer-1',
            'threadId' => self::THREAD_ID,
            'labelIds' => ['INBOX', 'UNREAD'],
            'headers'  => [
                'Message-ID'  => '<answer-1@example.test>',
                'In-Reply-To' => '<' . self::SENT_ID . '>',
                'Subject'     => '...',
                'From'        => 'friend@example.test',
                'To'          => 'gmail@example.test',
            ],
        ]);

        $this->em->refresh($sent);

        $answer = $this->em->getRepository(Message::class)->findOneBy(['messageId' => 'answer-1@example.test']);

        self::assertNotNull($answer);
        self::assertSame($sent->thread?->id, $answer->thread?->id);
        self::assertSame('gmail-thread-0', $answer->thread?->providerThreadKey, 'the conversation keeps its own key');
        self::assertSame(self::THREAD_ID, $answer->providerThreadKey);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{id: string, threadId: string, labelIds: list<string>, headers: array<string, string>} */
    private function sentCopy(): array
    {
        return [
            'id'       => 'gmail-sent-1',
            'threadId' => self::THREAD_ID,
            'labelIds' => ['SENT'],
            'headers'  => [
                'Message-ID' => '<' . self::SENT_ID . '>',
                'Subject'    => 'Flight',
                'From'       => 'gmail@example.test',
                'To'         => 'friend@example.test',
            ],
        ];
    }

    /**
     * Run the batch handler for one message, as GmailDraftSentElsewhereTest
     * does: the real client against a MockHttpClient answering with a
     * multipart batch body, because that is what the client parses.
     *
     * @param array{id: string, threadId: string, labelIds: list<string>, headers: array<string, string>} $message
     */
    private function handle(array $message): void
    {
        $headers = [];

        foreach ($message['headers'] as $name => $value) {
            $headers[] = ['name' => $name, 'value' => $value];
        }

        $payload = json_encode([
            'id'       => $message['id'],
            'threadId' => $message['threadId'],
            'labelIds' => $message['labelIds'],
            'payload'  => ['headers' => $headers],
        ], JSON_THROW_ON_ERROR);

        $boundary = 'batch_plmail_test';
        $body     = "--{$boundary}\r\n"
            . "Content-Type: application/http\r\n"
            . "Content-ID: <response-{$message['id']}>\r\n"
            . "\r\n"
            . "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . "\r\n"
            . $payload . "\r\n"
            . "--{$boundary}--";

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, [
            'response_headers' => ['content-type' => 'multipart/mixed; boundary="' . $boundary . '"'],
        ]));

        $container = self::getContainer();

        $handler = new SyncGmailMessageBatchHandler(
            $container->get(MessageThreadingRepository::class),
            $container->get(MessageProviderIdRepository::class),
            $container->get('App\Repository\Mail\Account\AccountRepository'),
            new GmailApiClient($httpClient, $container->get('App\Service\OAuth\OAuthTokenManager'), new GmailQuotaPacer(new MockClock())),
            $container->get('App\Service\Gmail\GmailMessageBuilder'),
            $container->get('App\Service\Gmail\GmailAddressFilter'),
            $container->get('App\Service\HarvestContactsService'),
            new SyncNotifier($this->createStub(HubInterface::class), new NullLogger()),
            $container->get('messenger.default_bus'),
            $container->get('App\Service\Mail\PostIngestPipeline'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            new NullLogger(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            $container->get('App\Service\Mail\MessageCategorizer'),
            $container->get('App\Service\Imap\MessageThreader'),
            new SyncOrigin(),
            new GmailQuotaPacer(new MockClock()),
            $container->get(GmailSkippedMessageRepository::class),
        );

        $handler(new SyncGmailMessageBatchMessage((int) $this->account->id, [$message['id']]));

        $this->em->flush();
    }

    private function rowsWithMessageId(string $messageId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message WHERE message_id = :id AND account_id = :account',
            ['id' => $messageId, 'account' => $this->account->id],
        );
    }

    /**
     * The row MessageSendService leaves behind: sent, stamped with the
     * Message-ID it went out under, no Gmail id, in a thread of its own that
     * Gmail has not named yet.
     */
    private function seedSentRow(): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Flight';
        $thread->normalizedSubject = 'flight';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Flight';
        $message->fromAddress    = 'gmail@example.test';
        $message->messageId      = self::SENT_ID;
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->sentAt         = new DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new DateTimeImmutable();

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Gmail fixture';
        $account->email          = 'gmail@example.test';
        $account->username       = uniqid('gmail-', true);
        $account->imapHost       = 'imap.gmail.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';

        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'google';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->mailEnabled      = true;

        $this->em->persist($account);
        $this->em->flush();

        $user->addAccount($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'gmail-sent-copy-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'SentCopy';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
