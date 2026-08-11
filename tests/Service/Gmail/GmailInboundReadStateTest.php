<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
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
 * Read state that arrives as a Gmail label, and whether plMail hears it.
 *
 * Gmail models read and starred as the labels UNREAD and STARRED, so both
 * arrive in the labelIds of a message re-fetched after a label change — which
 * is exactly what GmailApiSyncer re-fetches a stored message *for*. Two
 * separate things stopped that working, and both are fixed here:
 *
 *  1. The batch handler dropped every id it already had before fetching
 *     anything, which silently undid the planner's deliberate decision to send
 *     relabelled ids through. The planner's own comment says they bypass that
 *     filter; the handler re-applied it.
 *
 *  2. Even past that, enrichment mirrored labels and explicitly left read state
 *     alone — so UNREAD coming off in Gmail meant nothing here.
 *
 * Together those made Gmail's gap the same as plain IMAP's: read state captured
 * once at ingest and never re-read. Mail read on a phone stayed unread in
 * plMail forever.
 */
final class GmailInboundReadStateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    private const string GMAIL_ID = 'gmail-message-1';

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

    // ── the truth table ───────────────────────────────────────────────────

    /** UNREAD gone from the labels, stored unread → read. The reported gap. */
    public function testAMessageReadInGmailBecomesReadHere(): void
    {
        $message = $this->seedMessage(seen: false);

        $this->handle(['INBOX']);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt);
        self::assertContains(MessageFlag::SEEN->value, $message->flags);
    }

    /** UNREAD back on, stored read → unread. */
    public function testAMessageMarkedUnreadInGmailBecomesUnreadHere(): void
    {
        $message = $this->seedMessage(seen: true);

        $this->handle(['INBOX', 'UNREAD']);

        $this->em->refresh($message);

        self::assertNull($message->seenAt);
        self::assertNotContains(MessageFlag::SEEN->value, $message->flags);
    }

    /** STARRED on, stored unstarred → starred, thread included. */
    public function testAStarSetInGmailArrivesHere(): void
    {
        $message = $this->seedMessage(seen: false);

        $this->handle(['INBOX', 'UNREAD', 'STARRED']);

        $this->em->refresh($message);

        self::assertNotNull($message->starredAt);
        self::assertNotNull($message->thread->starredAt);
    }

    /** STARRED gone, stored starred → unstarred. */
    public function testAStarRemovedInGmailComesOffHere(): void
    {
        $message = $this->seedMessage(seen: false, flagged: true);

        $this->handle(['INBOX', 'UNREAD']);

        $this->em->refresh($message);

        self::assertNull($message->starredAt);
    }

    /**
     * The first of the two bugs, pinned on its own: an id plMail already holds
     * must still be fetched, because being already held is the whole reason a
     * relabelled id is sent.
     */
    public function testAnAlreadyStoredIdIsStillFetchedSoItsLabelsCanBeReRead(): void
    {
        $message = $this->seedMessage(seen: false);

        $requests = $this->handle(['INBOX']);

        self::assertSame(1, $requests, 'the batch was actually sent');

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt, 'and the re-read reached the row');
    }

    /**
     * The echo race, on Gmail. The guard lives in ThreadStatusUpdater, so it
     * covers this provider without Gmail having to implement it again.
     */
    public function testAnUnconfirmedLocalChangeIsNotRevertedByStaleLabels(): void
    {
        $message = $this->seedMessage(seen: false);

        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [MessageFlag::SEEN->value];
        $message->flagsTouchedAt = new DateTimeImmutable();
        $this->em->flush();

        // Gmail has not been told yet, so it still reports UNREAD.
        $this->handle(['INBOX', 'UNREAD']);

        $this->em->refresh($message);

        self::assertNotNull($message->seenAt, 'the local change is still in flight');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Run the batch handler for our one message, with Gmail reporting these
     * labelIds for it.
     *
     * GmailApiClient is final, so it is built for real against a
     * MockHttpClient — the way GmailApiSyncerHistoryTest does it — and the
     * response is a genuine multipart/mixed batch body, because that is what
     * the client parses.
     *
     * @param list<string> $labelIds
     *
     * @return int how many HTTP requests the client made
     */
    private function handle(array $labelIds): int
    {
        $requests = 0;

        $payload = json_encode([
            'id'       => self::GMAIL_ID,
            'threadId' => 'gmail-thread-1',
            'labelIds' => $labelIds,
            'payload'  => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<gmail-1@example.test>'],
                    ['name' => 'Subject', 'value' => 'Read elsewhere'],
                    ['name' => 'From', 'value' => 'sender@example.test'],
                    ['name' => 'To', 'value' => 'gmail@example.test'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $boundary = 'batch_plmail_test';

        $body = "--{$boundary}\r\n"
            . "Content-Type: application/http\r\n"
            . "Content-ID: <response-{$this->contentId()}>\r\n"
            . "\r\n"
            . "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . "\r\n"
            . $payload . "\r\n"
            . "--{$boundary}--";

        $httpClient = new MockHttpClient(function () use ($body, $boundary, &$requests): MockResponse {
            ++$requests;

            return new MockResponse($body, [
                'response_headers' => ['content-type' => 'multipart/mixed; boundary="' . $boundary . '"'],
            ]);
        });

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
        );

        $handler(new SyncGmailMessageBatchMessage((int) $this->account->id, [self::GMAIL_ID]));

        $this->em->flush();

        return $requests;
    }

    private function contentId(): string
    {
        return self::GMAIL_ID;
    }

    private function seedMessage(bool $seen, bool $flagged = false): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Read elsewhere';
        $thread->normalizedSubject = 'read elsewhere';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->unreadCount       = true === $seen ? 0 : 1;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Read elsewhere';
        $message->fromAddress    = 'sender@example.test';
        $message->messageId      = 'gmail-1@example.test';
        $message->gmailId        = self::GMAIL_ID;
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new DateTimeImmutable();

        if (true === $seen) {
            $message->seenAt = new DateTimeImmutable('-30 minutes');
            $message->flags  = [MessageFlag::SEEN->value];
        }

        if (true === $flagged) {
            $message->starredAt = new DateTimeImmutable('-30 minutes');
            $thread->starredAt  = $message->starredAt;
            $message->flags     = MessageFlag::canonicalList(
                array_merge($message->flags, [MessageFlag::FLAGGED->value]),
            );
        }

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
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
        $user->email     = 'gmail-read-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'ReadState';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
