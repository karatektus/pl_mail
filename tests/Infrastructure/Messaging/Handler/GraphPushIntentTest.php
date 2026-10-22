<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyGraphChangesHandler;
use App\Infrastructure\Messaging\Message\ApplyGraphChangesMessage;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What the user did reaches Outlook, whatever row a worker happens to load.
 *
 * ThreadStatusUpdater::markRead() queues the Graph push and then flushes, so a
 * worker can pick the job up while the database still holds the old row. The
 * handler used to build isRead from that row, which pushed `isRead: false` for
 * a message the user had just read: Microsoft was written at the moment of the
 * read (lastModifiedDateTime matched it to the second) and still showed the
 * message as unread. Seen in production on 2026-10-14 for 5 of 55 messages in
 * one bulk mark-read.
 *
 * The job now carries the read state or flag the user chose, and the handler
 * prefers it to the row. Every other action leaves both null and is derived
 * from the row as before.
 */
final class GraphPushIntentTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    /** @var array<string, array<string,mixed>> graph id => the PATCH body the handler sent */
    private array $patches = [];

    /** @var int the status every sub-request of the batch answers with */
    private int $subStatus = 200;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->patches    = [];
        $this->subStatus  = 200;

        $this->connection->beginTransaction();
        $this->exportTransport()->reset();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── The race, end to end ──────────────────────────────────────────────

    /**
     * The production failure. The job is captured as markRead() queues it, the
     * row is then put back to what a worker that won the race would have
     * loaded, and the real handler runs on that.
     */
    public function testAReadMarkedInPlMailIsPushedAsReadWhenTheWorkerLoadsTheOldRow(): void
    {
        $message = $this->message(read: false);
        $this->em->flush();

        $queued = $this->captureGraphJob();

        self::getContainer()->get(ThreadStatusUpdater::class)->markRead([$message], true);

        self::assertNotNull($queued->job, 'a Graph job was queued for the read');

        $this->putTheRowBackToUnread($message);

        $this->runHandler($queued->job);

        self::assertTrue($this->patches[$message->graphId]['isRead'], 'Outlook is told the message is read');
    }

    /** And the other direction: an unread mark survives a worker that still sees a read row. */
    public function testAnUnreadMarkedInPlMailIsPushedAsUnreadWhenTheWorkerLoadsTheOldRow(): void
    {
        $message = $this->message(read: true);
        $this->em->flush();

        $queued = $this->captureGraphJob();

        self::getContainer()->get(ThreadStatusUpdater::class)->markRead([$message], false);

        self::assertNotNull($queued->job, 'a Graph job was queued for the unread mark');

        $this->connection->executeStatement(
            'UPDATE message SET seen_at = :seen, flags = :flags WHERE id = :id',
            ['seen' => '2026-08-10 09:00:00', 'flags' => json_encode([MessageFlag::SEEN->value]), 'id' => $message->id],
        );
        $this->em->clear();

        $this->runHandler($queued->job);

        self::assertFalse($this->patches[$message->graphId]['isRead'], 'Outlook is told the message is unread');
    }

    // ── The handler prefers what it was told ──────────────────────────────

    public function testTheJobsReadStateBeatsAStaleRow(): void
    {
        $unreadRow = $this->message(read: false);
        $readRow   = $this->message(read: true);
        $this->em->flush();
        $this->em->clear();

        $this->runHandler(new ApplyGraphChangesMessage(
            (int) $this->account->id,
            [(int) $unreadRow->id],
            isRead: true,
        ));
        $this->runHandler(new ApplyGraphChangesMessage(
            (int) $this->account->id,
            [(int) $readRow->id],
            isRead: false,
        ));

        self::assertTrue($this->patches[$unreadRow->graphId]['isRead'], 'told read, row unread: read');
        self::assertFalse($this->patches[$readRow->graphId]['isRead'], 'told unread, row read: unread');
    }

    public function testTheJobsFlagBeatsAStaleRow(): void
    {
        $plain   = $this->message(read: true);
        $starred = $this->message(read: true);
        $starred->starredAt = new DateTimeImmutable();
        $this->em->flush();
        $this->em->clear();

        $this->runHandler(new ApplyGraphChangesMessage((int) $this->account->id, [(int) $plain->id], flagged: true));
        $this->runHandler(new ApplyGraphChangesMessage((int) $this->account->id, [(int) $starred->id], flagged: false));

        self::assertSame('flagged', $this->patches[$plain->graphId]['flag']['flagStatus']);
        self::assertSame('notFlagged', $this->patches[$starred->graphId]['flag']['flagStatus']);
    }

    /**
     * The companion to both: with nothing said, the row decides, so archive,
     * trash and label pushes behave exactly as they did. Without this the two
     * tests above could pass by the handler ignoring the row altogether.
     */
    public function testWithNothingSaidTheRowDecides(): void
    {
        $unread  = $this->message(read: false);
        $read    = $this->message(read: true);
        $starred = $this->message(read: true);
        $starred->starredAt = new DateTimeImmutable();
        $this->em->flush();
        $this->em->clear();

        $this->runHandler(new ApplyGraphChangesMessage((int) $this->account->id, [(int) $unread->id, (int) $read->id, (int) $starred->id]));

        self::assertFalse($this->patches[$unread->graphId]['isRead']);
        self::assertTrue($this->patches[$read->graphId]['isRead']);
        self::assertSame('flagged', $this->patches[$starred->graphId]['flag']['flagStatus']);
        self::assertSame('notFlagged', $this->patches[$read->graphId]['flag']['flagStatus']);
    }

    // ── A retry pushes the latest state, not the original intent ──────────

    /**
     * A throttled push is requeued thirty seconds on. By then the row is long
     * flushed, and the user may have changed their mind in between; a retry
     * that replayed the original choice would overwrite the newer one.
     */
    public function testAThrottledRetryDropsTheOverridesAndLetsTheRowDecide(): void
    {
        $first  = $this->message(read: false);
        $second = $this->message(read: false);
        $this->em->flush();
        $this->em->clear();

        $this->subStatus = 429;

        $this->runHandler(new ApplyGraphChangesMessage(
            (int) $this->account->id,
            [(int) $first->id, (int) $second->id],
            isRead: true,
            flagged: true,
        ));

        $retries = $this->sentJobs();

        self::assertCount(1, $retries, 'the throttled writes were requeued');
        self::assertEqualsCanonicalizing([(int) $first->id, (int) $second->id], $retries[0]->messageIds, 'all of them');
        self::assertNull($retries[0]->isRead, 'the retry does not replay the read state');
        self::assertNull($retries[0]->flagged, 'nor the flag');
    }

    // ── Jobs already in the queue when this ships ─────────────────────────

    /**
     * A job serialized before the two fields existed comes back with them
     * uninitialised, not null. Reading one directly is an Error, and the worker
     * would reject every Graph push that was queued across the upgrade.
     */
    public function testAJobQueuedBeforeTheFieldsExistedStillRunsAndFollowsTheRow(): void
    {
        $unread = $this->message(read: false);
        $read   = $this->message(read: true);
        $this->em->flush();
        $this->em->clear();

        $class  = ApplyGraphChangesMessage::class;
        $legacy = sprintf(
            'O:%d:"%s":3:{s:9:"accountId";i:%d;s:10:"messageIds";a:2:{i:0;i:%d;i:1;i:%d;}s:11:"moveToLabel";N;}',
            strlen($class),
            $class,
            (int) $this->account->id,
            (int) $unread->id,
            (int) $read->id,
        );

        $job = unserialize($legacy);

        self::assertInstanceOf(ApplyGraphChangesMessage::class, $job);

        $this->runHandler($job);

        self::assertFalse($this->patches[$unread->graphId]['isRead']);
        self::assertTrue($this->patches[$read->graphId]['isRead']);
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /**
     * Collects the Graph job as dispatch() hands it to the transport.
     */
    private function captureGraphJob(): object
    {
        $capture = new class {
            public ?ApplyGraphChangesMessage $job = null;
        };

        self::getContainer()->get('event_dispatcher')->addListener(
            SendMessageToTransportsEvent::class,
            static function (SendMessageToTransportsEvent $event) use ($capture): void {
                $message = $event->getEnvelope()->getMessage();

                if (true === $message instanceof ApplyGraphChangesMessage) {
                    $capture->job = $message;
                }
            },
        );

        return $capture;
    }

    /**
     * The row as a worker that beat the flush would have loaded it.
     */
    private function putTheRowBackToUnread(Message $message): void
    {
        $this->connection->executeStatement(
            'UPDATE message SET seen_at = NULL, flags = :flags WHERE id = :id',
            ['flags' => '[]', 'id' => $message->id],
        );
        $this->em->clear();
    }

    private function runHandler(ApplyGraphChangesMessage $job): void
    {
        $container = self::getContainer();

        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = str_replace('/v1.0', '', (string) parse_url($url, PHP_URL_PATH));

            if ('POST' === $method && str_ends_with($path, '/$batch')) {
                $requests  = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR)['requests'];
                $responses = [];

                foreach ($requests as $request) {
                    $this->patches[rawurldecode(basename((string) $request['url']))] = $request['body'];
                    $responses[] = ['id' => $request['id'], 'status' => $this->subStatus, 'body' => []];
                }

                return $this->json(['responses' => $responses]);
            }

            return $this->json(['value' => []]);
        });

        $handler = new ApplyGraphChangesHandler(
            $container->get('App\Repository\Mail\AccountRepository'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('App\Repository\Label\LabelRepository'),
            new GraphApiClient($client, $container->get('App\Service\OAuth\OAuthTokenManager')),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get('messenger.default_bus'),
            $this->em,
            new \Psr\Log\NullLogger(),
            $container->get(LabelResolver::class),
        );

        $handler($job);
    }

    /** @return list<ApplyGraphChangesMessage> */
    private function sentJobs(): array
    {
        $found = [];

        foreach ($this->exportTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if (true === $message instanceof ApplyGraphChangesMessage) {
                $found[] = $message;
            }
        }

        return $found;
    }

    private function exportTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.export');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function message(bool $read): Message
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Rechnung';
        $thread->normalizedSubject = 'rechnung';
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 1;
        $thread->unreadCount       = true === $read ? 0 : 1;
        $thread->attachmentCount   = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account        = $this->account;
        $message->graphId        = 'AAkALgAAmessage-' . uniqid();
        $message->messageId      = uniqid('', true) . '@example.test';
        $message->subject        = 'Rechnung';
        $message->fromAddress    = 'kunde@example.test';
        $message->bodyText       = 'Rechnung';
        $message->hasAttachments = false;
        $message->seenAt         = true === $read ? new DateTimeImmutable('2026-08-10 09:00:00') : null;
        $message->flags          = true === $read ? [MessageFlag::SEEN->value] : [];
        $message->receivedAt     = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt         = $message->receivedAt;

        $this->em->persist($message);
        $thread->addMessage($message);

        return $message;
    }

    private function seed(): void
    {
        $user = new User();
        $user->email     = 'graph-intent-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Intent';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $this->account = new Account();
        $this->account->usr            = $user;
        $this->account->email          = 'graph-intent-fixture@example.test';
        $this->account->username       = 'graph-intent-fixture@example.test';
        $this->account->imapHost       = 'outlook.office365.com';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'smtp.office365.com';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = AuthType::OAuth2->value;
        $this->account->oauthProvider  = MailProvider::Microsoft->value;
        $this->account->isActive       = true;
        // The client asks the token manager before every request; without a
        // token that is good for a while it tries to refresh one and fails.
        $this->account->oauthAccessToken = 'test-access-token';
        $this->account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $this->em->persist($this->account);

        $this->em->flush();

        $user->addAccount($this->account);

        $this->em->flush();
    }
}
