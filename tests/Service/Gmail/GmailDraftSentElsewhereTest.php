<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use Psr\Log\NullLogger;
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
 * A draft sent from Gmail stops being a draft here.
 *
 * Gmail keeps the message and swaps its labels: DRAFT comes off and SENT goes
 * on. Enrichment re-reads exactly that label set, but only mirrored the labels
 * and the read state, so the stored row stayed an unsent draft: the editor
 * would open it and the send queue would send it again. The label set is
 * authoritative, so a stored draft whose message no longer carries DRAFT is
 * settled as sent; one that still carries it is left alone.
 */
final class GmailDraftSentElsewhereTest extends KernelTestCase
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

    public function testADraftThatGmailNowLabelsSentIsNoLongerADraft(): void
    {
        $draft  = $this->seedDraft();
        $before = new DateTimeImmutable();

        $this->handle(['SENT']);

        $this->em->refresh($draft);

        self::assertFalse($draft->isDraft());
        self::assertNotNull($draft->sentAt);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $draft->sentAt->getTimestamp());
    }

    /** Neither label: not known to be sent, so not settled. */
    public function testADraftThatGmailLabelsNeitherDraftNorSentStaysADraft(): void
    {
        $draft = $this->seedDraft();

        $this->handle(['TRASH']);

        $this->em->refresh($draft);

        self::assertTrue($draft->isDraft());
        self::assertNull($draft->sentAt);
    }

    public function testADraftThatGmailStillLabelsDraftStaysADraft(): void
    {
        $draft = $this->seedDraft();

        $this->handle(['DRAFT']);

        $this->em->refresh($draft);

        self::assertTrue($draft->isDraft());
        self::assertNull($draft->sentAt);
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
    private function handle(array $labelIds, bool $onlyMissing = false): int
    {
        $requests = 0;

        $payload = json_encode([
            'id'       => self::GMAIL_ID,
            'threadId' => 'gmail-thread-1',
            'labelIds' => $labelIds,
            'payload'  => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<gmail-1@example.test>'],
                    ['name' => 'Subject', 'value' => 'Sent from Gmail'],
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
            new GmailApiClient($httpClient, $container->get('App\Service\OAuth\OAuthTokenManager'), new \App\Service\Gmail\GmailQuotaPacer(new \Symfony\Component\Clock\MockClock())),
            $container->get('App\Service\Gmail\GmailMessageBuilder'),
            $container->get('App\Service\Gmail\GmailAddressFilter'),
            $container->get('App\Service\HarvestContactsService'),
            // The real notifier publishes to Mercure, which the test kernel has
            // no signing key for. What it announces is not what is under test.
            new SyncNotifier($this->createStub(HubInterface::class), new NullLogger()),
            $container->get('messenger.default_bus'),
            $container->get('App\Service\Mail\PostIngestPipeline'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            new \Psr\Log\NullLogger(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            $container->get('App\Service\Mail\MessageCategorizer'),
            $container->get('App\Service\Imap\MessageThreader'),
            new \App\Service\Mail\SyncOrigin(),
            new \App\Service\Gmail\GmailQuotaPacer(new \Symfony\Component\Clock\MockClock()),
            $container->get(\App\Repository\Mail\GmailSkippedMessageRepository::class),
        );

        $handler(new SyncGmailMessageBatchMessage((int) $this->account->id, [self::GMAIL_ID], null, $onlyMissing));

        $this->em->flush();

        return $requests;
    }

    private function contentId(): string
    {
        return self::GMAIL_ID;
    }

    /** A draft as the sync stored it: \Draft, no sentAt. */
    private function seedDraft(): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Sent from Gmail';
        $thread->normalizedSubject = 'sent from gmail';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 day');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Sent from Gmail';
        $message->fromAddress    = 'gmail@example.test';
        $message->messageId      = 'gmail-1@example.test';
        $message->gmailId        = self::GMAIL_ID;
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->flags          = [MessageFlag::DRAFT->value];
        $message->syncedAt       = new DateTimeImmutable();

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
        $user->email     = 'gmail-draft-sent-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'DraftSent';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
