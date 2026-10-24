<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
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
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What the sync does when Graph says a message has attachments and the listing
 * of them comes back empty-handed.
 *
 * That gap is how a rejected $select went unseen: the listing 400'd, the
 * message was stored as having no attachment, and nothing was logged, across
 * every Outlook message that had a file on it. The 400 is the client's to
 * explain; what this handler owes is that a message Graph vouches for and the
 * listing does not cover is noticed.
 */
final class SyncGraphAttachmentListingTest extends KernelTestCase
{
    private const string WARNING = 'SyncGraphMessageBatch: attachment listing returned nothing';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    /** @var list<array{level: string, message: string, context: array<string,mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->logged     = [];

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

    public function testAMessageGraphVouchesForButTheListingMissesIsWarnedAbout(): void
    {
        $this->sync(listingStatus: 400);

        $warnings = $this->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(['AAkALgAAmessage'], $warnings[0]['context']['graphIds']);
        self::assertSame(1, $warnings[0]['context']['count']);

        // Stored all the same: a listing that failed must not lose the mail.
        self::assertNotNull($this->stored(), 'the message itself still arrives');
    }

    /**
     * The other half, and the one that shows the first is not just "always
     * warns": with the listing answering, the file is stored and nothing is
     * said.
     */
    public function testAListingThatAnswersIsStoredAndNotWarnedAbout(): void
    {
        $this->sync(listingStatus: 200);

        self::assertSame([], $this->warnings());

        $message = $this->stored();

        self::assertNotNull($message);
        self::assertTrue($message->hasAttachments);
        self::assertCount(1, $message->messageParts);
        self::assertSame('Fwd_ IONOS.zip', $message->messageParts->first()->filename);
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function sync(int $listingStatus): void
    {
        $container = self::getContainer();

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($listingStatus): ResponseInterface {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);
            $subs = [];

            foreach ($body['requests'] ?? [] as $request) {
                $subs[] = str_contains((string) $request['url'], '/attachments')
                    ? $this->attachmentListing((string) $request['id'], $listingStatus)
                    : ['id' => (string) $request['id'], 'status' => 200, 'body' => $this->payload()];
            }

            return new MockResponse(
                json_encode(['responses' => $subs], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string,mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

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
            $logger,
            $container->get(SyncOrigin::class),
        );

        $handler(new SyncGraphMessageBatchMessage((int) $this->account->id, ['AAkALgAAmessage']));

        $this->em->flush();

        $this->logged = $logger->records;
    }

    /** @return list<array{level: string, message: string, context: array<string,mixed>}> */
    private function warnings(): array
    {
        return array_values(array_filter(
            $this->logged,
            static fn (array $entry): bool => 'warning' === $entry['level'] && self::WARNING === $entry['message'],
        ));
    }

    private function stored(): ?Message
    {
        $this->em->clear();

        return $this->em->getRepository(Message::class)->findOneBy(['graphId' => 'AAkALgAAmessage']);
    }

    /** @return array<string,mixed> */
    private function attachmentListing(string $id, int $status): array
    {
        if (200 !== $status) {
            return [
                'id'     => $id,
                'status' => $status,
                'body'   => ['error' => ['code' => 'BadRequest', 'message' => 'Parsing OData Select and Expand failed.']],
            ];
        }

        return [
            'id'     => $id,
            'status' => 200,
            'body'   => ['value' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id'          => 'att-1',
                'name'        => 'Fwd_ IONOS.zip',
                'contentType' => 'application/x-zip-compressed',
                'size'        => 73228,
                'isInline'    => false,
                'contentId'   => '9FDD6538@EURP192.PROD.OUTLOOK.COM',
            ]]],
        ];
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'id'                => 'AAkALgAAmessage',
            'internetMessageId' => '<' . uniqid('', true) . '@example.test>',
            'conversationId'    => 'conv-1',
            'subject'           => 'Re: Wahrscheinlich Phishing',
            'from'              => ['emailAddress' => ['name' => 'Martin', 'address' => 'martin@example.test']],
            'toRecipients'      => [['emailAddress' => ['name' => 'Walid', 'address' => 'graph-att-fixture@example.test']]],
            'receivedDateTime'  => '2026-10-11T18:36:05Z',
            'sentDateTime'      => '2026-10-11T18:35:31Z',
            'isRead'            => false,
            'isDraft'           => false,
            'body'              => ['contentType' => 'text', 'content' => 'Haha'],
            'bodyPreview'       => 'Haha',
            'hasAttachments'    => true,
            'parentFolderId'    => 'inbox-folder',
        ];
    }

    private function seed(): void
    {
        $user = new User();
        $user->email     = 'graph-att-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Attachments';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $this->account = new Account();
        $this->account->usr              = $user;
        $this->account->email            = 'graph-att-fixture@example.test';
        $this->account->username         = 'graph-att-fixture@example.test';
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
