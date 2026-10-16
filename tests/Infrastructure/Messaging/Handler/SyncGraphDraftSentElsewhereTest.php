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
use App\Infrastructure\Messaging\Handler\SyncGraphMessageBatchHandler;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Graph\GraphMessageBuilder;
use App\Service\HarvestContactsService;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\SyncNotifier;
use App\Service\Mail\SyncOrigin;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A draft sent from Outlook, when Outlook gives the sent copy a new id.
 *
 * Without immutable ids (personal outlook.com) filing a draft in Sent Items
 * changes its id. The handler recognises the message by its RFC Message-ID,
 * keeps the stored row and only re-points the locator, so the row stayed an
 * unsent draft: openable in the editor, and sendable a second time. The full
 * resource it has just fetched says isDraft, and that is the answer.
 *
 * The same message fetched again and still a draft is the companion: the
 * handler must not settle everything it re-points.
 */
final class SyncGraphDraftSentElsewhereTest extends KernelTestCase
{
    private const string OLD_ID  = 'AAkALgAAdraft-old';
    private const string NEW_ID  = 'AAkALgAAdraft-new';
    private const string RFC_ID  = '<draft-elsewhere@example.test>';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSentCopyOfAStoredDraftSettlesTheDraft(): void
    {
        $draft = $this->seedDraft();

        $this->sync(isDraft: false);

        $this->em->refresh($draft);

        // Presence: it is the same row, re-pointed, not a second message.
        self::assertSame(self::NEW_ID, $draft->graphId);

        self::assertFalse($draft->isDraft());
        // sentDateTime from the fetched resource: 2026-10-14T14:20:00Z.
        self::assertSame(gmmktime(14, 20, 0, 10, 14, 2026), $draft->sentAt?->getTimestamp());
    }

    public function testTheSameMessageStillADraftStaysADraft(): void
    {
        $draft = $this->seedDraft();

        $this->sync(isDraft: true);

        $this->em->refresh($draft);

        self::assertSame(self::NEW_ID, $draft->graphId);

        self::assertTrue($draft->isDraft());
        self::assertNull($draft->sentAt);
    }

    /** Absence is not an answer: a payload that omits isDraft settles nothing. */
    public function testAPayloadThatDoesNotSayIfItIsADraftSettlesNothing(): void
    {
        $draft = $this->seedDraft();

        $this->sync(isDraft: null);

        $this->em->refresh($draft);

        // Presence: the handler did meet it and re-point it.
        self::assertSame(self::NEW_ID, $draft->graphId);

        self::assertTrue($draft->isDraft());
        self::assertNull($draft->sentAt);
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function sync(?bool $isDraft): void
    {
        $container = self::getContainer();

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($isDraft): MockResponse {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);
            $subs = [];

            foreach ($body['requests'] ?? [] as $request) {
                $subs[] = ['id' => (string) $request['id'], 'status' => 200, 'body' => $this->payload($isDraft)];
            }

            return new MockResponse(
                json_encode(['responses' => $subs], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $handler = new SyncGraphMessageBatchHandler(
            $container->get(MessageRepository::class),
            $container->get(AccountRepository::class),
            new GraphApiClient($client, $container->get(OAuthTokenManager::class)),
            $container->get(GraphMessageBuilder::class),
            $container->get(HarvestContactsService::class),
            $container->get(SyncNotifier::class),
            $container->get('messenger.default_bus'),
            $container->get(PostIngestPipeline::class),
            $this->em,
            new NullLogger(),
            $container->get(SyncOrigin::class),
        );

        $handler(new SyncGraphMessageBatchMessage((int) $this->account->id, [self::NEW_ID]));

        $this->em->flush();
    }

    /** @return array<string,mixed> */
    private function payload(?bool $isDraft): array
    {
        $payload = [
            'id'                => self::NEW_ID,
            'internetMessageId' => self::RFC_ID,
            'conversationId'    => 'conv-draft',
            'subject'           => 'Sent from Outlook',
            'from'              => ['emailAddress' => ['name' => 'Me', 'address' => 'graph-draft-fixture@example.test']],
            'toRecipients'      => [['emailAddress' => ['address' => 'you@example.test']]],
            'receivedDateTime'  => '2026-10-14T14:15:51Z',
            'sentDateTime'      => false === $isDraft ? '2026-10-14T14:20:00Z' : null,
            'isRead'            => true,
            'body'              => ['contentType' => 'text', 'content' => 'Hello'],
            'bodyPreview'       => 'Hello',
            'hasAttachments'    => false,
            'parentFolderId'    => false === $isDraft ? 'sent-folder' : 'drafts-folder',
        ];

        if (null !== $isDraft) {
            $payload['isDraft'] = $isDraft;
        }

        return $payload;
    }

    /** A draft as the sync stored it before it was sent: \Draft, no sentAt. */
    private function seedDraft(): Message
    {
        // The handler finds the stored message through its thread or mailbox,
        // and a synced draft always has a thread.
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Sent from Outlook';
        $thread->normalizedSubject = 'sent from outlook';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 day');

        $draft                 = new Message();
        $draft->account        = $this->account;
        $draft->thread         = $thread;
        $draft->subject        = 'Sent from Outlook';
        $draft->fromAddress    = $this->account->email;
        $draft->messageId      = 'draft-elsewhere@example.test';
        $draft->graphId        = self::OLD_ID;
        $draft->receivedAt     = new DateTimeImmutable('2026-10-14 14:15:51');
        $draft->hasAttachments = false;
        $draft->flags          = [MessageFlag::DRAFT->value];
        $draft->syncedAt       = new DateTimeImmutable();

        $thread->addMessage($draft);

        $this->em->persist($thread);
        $this->em->persist($draft);
        $this->em->flush();

        return $draft;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'graph-draft-sent-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'DraftSent';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $this->account                   = new Account();
        $this->account->usr              = $user;
        $this->account->email            = 'graph-draft-fixture@example.test';
        $this->account->username         = 'graph-draft-fixture@example.test';
        $this->account->imapHost         = 'outlook.office365.com';
        $this->account->imapPort         = 993;
        $this->account->imapEncryption   = 'ssl';
        $this->account->smtpHost         = 'smtp.office365.com';
        $this->account->smtpPort         = 587;
        $this->account->smtpEncryption   = 'starttls';
        $this->account->password         = 'x';
        $this->account->authType         = AuthType::OAuth2->value;
        $this->account->oauthProvider    = MailProvider::Microsoft->value;
        $this->account->isActive         = true;
        $this->account->oauthAccessToken = 'test-access-token';
        $this->account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $this->em->persist($this->account);

        $this->em->flush();

        $user->addAccount($this->account);

        $this->em->flush();
    }
}
