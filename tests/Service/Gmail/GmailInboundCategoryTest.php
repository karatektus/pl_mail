<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SyncGmailMessageBatchHandler;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\SyncNotifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * The inbox category that arrives as a Gmail label, on mail plMail already has.
 *
 * Gmail classifies after delivery and re-classifies whenever the user moves a
 * message between tabs, so CATEGORY_* comes and goes on rows that have been
 * stored for months — which is exactly what a relabel re-fetch carries. The
 * category was written once at ingest and enrichment never recomputed it, so
 * the reading pane, which explains the category live from the labels, said
 * "Promotions — Gmail said so" over a message the inbox tabs still filed under
 * Primary. Only `app:backfill category` closed the gap, by hand.
 *
 * The tabs filter on the thread's resolved category, not the message's, so both
 * halves are asserted here: the row, and the thread that most-recent-wins puts
 * it on.
 */
final class GmailInboundCategoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    private const string GMAIL_ID = 'gmail-category-1';

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The reported gap: Gmail moved it to Promotions, plMail kept it in Primary. */
    public function testAMessageMovedToPromotionsInGmailLeavesPrimaryHere(): void
    {
        $message = $this->seedMessage();

        $this->handle(['INBOX', 'CATEGORY_PROMOTIONS']);

        $this->em->refresh($message);
        $this->em->refresh($message->thread);

        self::assertSame(MessageCategory::Promotions, $message->category);
        self::assertSame(MessageCategory::Promotions, $message->thread->category);
    }

    /** And back: the label comes off, the message returns to Primary. */
    public function testACategoryLabelRemovedInGmailReturnsTheMessageToPrimary(): void
    {
        $message           = $this->seedMessage();
        $message->category = MessageCategory::Promotions;
        $thread            = $message->thread;
        $thread->category  = MessageCategory::Promotions;
        $this->em->flush();

        $this->handle(['INBOX']);

        $this->em->refresh($message);
        $this->em->refresh($thread);

        self::assertSame(MessageCategory::Primary, $message->category);
        self::assertSame(MessageCategory::Primary, $thread->category);
    }

    /**
     * Most-recent-wins is the whole rule. A relabelled message that is no
     * longer the newest in its thread recategorises itself and leaves the
     * thread — and so the tab the conversation sits in — alone.
     */
    public function testAnOlderMessageDoesNotDragItsThreadOutOfPrimary(): void
    {
        $message = $this->seedMessage();
        $thread  = $message->thread;

        $this->seedReply($thread);

        $this->handle(['INBOX', 'CATEGORY_PROMOTIONS']);

        $this->em->refresh($message);
        $this->em->refresh($thread);

        self::assertSame(MessageCategory::Promotions, $message->category, 'the row itself is current');
        self::assertSame(MessageCategory::Primary, $thread->category, 'the newest message still owns the thread');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Run the batch handler for our one message, with Gmail reporting these
     * labelIds for it. Same shape as GmailInboundReadStateTest: GmailApiClient
     * is final, so it is built for real against a MockHttpClient returning a
     * genuine multipart/mixed batch body.
     *
     * @param list<string> $labelIds
     */
    private function handle(array $labelIds): void
    {
        $payload = json_encode([
            'id'       => self::GMAIL_ID,
            'threadId' => 'gmail-thread-1',
            'labelIds' => $labelIds,
            'payload'  => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<gmail-category-1@example.test>'],
                    ['name' => 'Subject', 'value' => 'Half price everything'],
                    ['name' => 'From', 'value' => 'sender@example.test'],
                    ['name' => 'To', 'value' => 'gmail@example.test'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $boundary = 'batch_plmail_test';

        $body = "--{$boundary}\r\n"
            . "Content-Type: application/http\r\n"
            . "Content-ID: <response-" . self::GMAIL_ID . ">\r\n"
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
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('App\Repository\Mail\AccountRepository'),
            new GmailApiClient($httpClient, $container->get('App\Service\OAuth\OAuthTokenManager')),
            $container->get('App\Service\Gmail\GmailMessageBuilder'),
            $container->get('App\Service\Gmail\GmailAddressFilter'),
            $container->get('App\Service\HarvestContactsService'),
            // The real notifier publishes to Mercure, which the test kernel has
            // no signing key for. What it announces is not what is under test.
            new SyncNotifier($this->createStub(HubInterface::class)),
            $container->get('messenger.default_bus'),
            $container->get('App\Service\Mail\PostIngestPipeline'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            new \Psr\Log\NullLogger(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            $container->get('App\Service\Mail\MessageCategorizer'),
            $container->get('App\Service\Imap\MessageThreader'),
        );

        $handler(new SyncGmailMessageBatchMessage((int) $this->account->id, [self::GMAIL_ID]));

        $this->em->flush();
    }

    private function seedMessage(): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Half price everything';
        $thread->normalizedSubject = 'half price everything';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->unreadCount       = 0;
        $thread->category          = MessageCategory::Primary;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Half price everything';
        $message->fromAddress    = 'sender@example.test';
        $message->messageId      = 'gmail-category-1@example.test';
        $message->gmailId        = self::GMAIL_ID;
        $message->gmailLabelIds  = ['INBOX'];
        $message->category       = MessageCategory::Primary;
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new DateTimeImmutable();

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** A newer message on the same thread, which is the one the tabs follow. */
    private function seedReply(MessageThread $thread): Message
    {
        $reply                 = new Message();
        $reply->account        = $this->account;
        $reply->thread         = $thread;
        $reply->subject        = 'Re: Half price everything';
        $reply->fromAddress    = 'friend@example.test';
        $reply->messageId      = 'gmail-category-2@example.test';
        $reply->gmailLabelIds  = ['INBOX'];
        $reply->category       = MessageCategory::Primary;
        $reply->receivedAt     = new DateTimeImmutable('-5 minutes');
        $reply->sentAt         = $reply->receivedAt;
        $reply->hasAttachments = false;
        $reply->flags          = [];
        $reply->syncedAt       = new DateTimeImmutable();

        $thread->addMessage($reply);
        $thread->lastMessageAt = $reply->receivedAt;

        $this->em->persist($reply);
        $this->em->flush();

        return $reply;
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
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
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        $this->user->addAccount($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'gmail-category-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'Category';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
