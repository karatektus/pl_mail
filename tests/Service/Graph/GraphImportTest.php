<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use App\Service\Graph\GraphApiSyncer;
use App\Service\Graph\GraphImportStep;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\InitialImportState;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * A Microsoft account's history, and that it is no longer the sync's to fetch.
 *
 * A folder with no delta link used to be enumerated to its end by whichever
 * sync met it, and every message in it sent to be fetched on the queue new
 * mail arrives on (#42). What is pinned here is the separation: the sync
 * plans such a folder and asks it only for what is new, and the import reads
 * it a page at a time, the Inbox first, onto a queue of its own.
 *
 * Every request the syncer makes is recorded, because half of these tests are
 * about a request that must NOT be made — and a mock that answers anything
 * would let a whole-folder enumeration through without a word.
 */
final class GraphImportTest extends KernelTestCase
{
    private const string INBOX   = 'AAMkAD-inbox';
    private const string TRASH   = 'AAMkAD-trash';
    private const string PROJECT = 'AAMkAD-project';

    private const string DELTA_LINK = 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=done';
    private const string NEXT_LINK  = 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$skiptoken=page2';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    /** @var list<array{method: string, url: string, headers: list<string>}> */
    private array $requests = [];

    /** @var \ArrayObject<int, Envelope> */
    private \ArrayObject $dispatched;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        // Seeded in the order Graph would NOT want them read in, so the order
        // asserted below is the ranking's doing and not the fixture's.
        $this->seedLabel('Trash', LabelRole::Trash, self::TRASH);
        $this->seedLabel('Project', null, self::PROJECT);
        $this->seedLabel('Inbox', LabelRole::Inbox, self::INBOX);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the sync plans, and does not enumerate ────────────────────────────

    public function testAFolderMetForTheFirstTimeIsPlannedRatherThanEnumerated(): void
    {
        $this->syncer([])->sync($this->account, [self::TRASH, self::PROJECT, self::INBOX]);

        self::assertSame([], $this->requests, 'the first sync asks no folder for its messages');
        self::assertCount(0, $this->dispatched);
        self::assertSame([], $this->account->graphDeltaLinks);
        self::assertTrue($this->account->needsGraphImport());
    }

    public function testTheInboxIsReadFirstAndTheBinLast(): void
    {
        $this->syncer([])->sync($this->account, [self::TRASH, self::PROJECT, self::INBOX]);

        self::assertSame(
            [self::INBOX, self::PROJECT, self::TRASH],
            array_column($this->account->graphImport, 'folder'),
        );
    }

    public function testAFolderBeingImportedIsAskedOnlyWhatItHasReceivedSince(): void
    {
        $this->account->graphImport = [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => null]];
        $this->em->flush();

        $this->syncer([$this->json(['value' => [['id' => 'new-1', 'parentFolderId' => self::INBOX]]])])
            ->sync($this->account, [self::INBOX]);

        self::assertCount(1, $this->requests);

        $url = urldecode($this->requests[0]['url']);

        self::assertStringContainsString('/mailFolders/' . self::INBOX . '/messages?', $url);
        self::assertStringNotContainsString('/delta', $url);
        self::assertStringContainsString('receivedDateTime ge 2027-01-15T08:00:00Z', $url);

        // And what it names goes where new mail goes: the live queue.
        self::assertSame([['new-1']], $this->batches());
        self::assertSame([null], $this->queues());
    }

    public function testAFolderWithADeltaLinkIsStillFollowedWhileAnotherImports(): void
    {
        $this->account->graphDeltaLinks = [self::INBOX => self::DELTA_LINK];
        $this->em->flush();

        $this->syncer([$this->json(['value' => [], '@odata.deltaLink' => self::DELTA_LINK . '2'])])
            ->sync($this->account, [self::INBOX, self::TRASH]);

        self::assertCount(1, $this->requests);
        self::assertSame(self::DELTA_LINK, $this->requests[0]['url']);
        self::assertSame([self::INBOX => self::DELTA_LINK . '2'], $this->account->graphDeltaLinks);
        self::assertSame([self::TRASH], array_column($this->account->graphImport, 'folder'));
    }

    public function testAnExpiredDeltaLinkHandsTheFolderBackToTheImport(): void
    {
        $this->account->graphDeltaLinks = [self::INBOX => self::DELTA_LINK];
        $this->account->lastSyncedAt    = new DateTimeImmutable('@1800000000');
        $this->em->flush();

        $this->syncer([new MockResponse('{"error":{"code":"SyncStateNotFound"}}', ['http_code' => 410])])
            ->sync($this->account, [self::INBOX]);

        self::assertCount(1, $this->requests, 'the folder is not enumerated again inside the sync');
        self::assertSame([], $this->account->graphDeltaLinks);
        self::assertSame(
            [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => null]],
            $this->account->graphImport,
        );
    }

    // ── the import reads a page at a time ─────────────────────────────────

    public function testAPageIsListedNewestFirstAndFetchedOnTheImportQueue(): void
    {
        $this->account->graphImport = [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => null]];
        $this->em->flush();

        $step = $this->syncer([$this->json([
            'value'           => [['id' => 'a', 'parentFolderId' => self::INBOX], ['id' => 'b', 'parentFolderId' => self::INBOX]],
            '@odata.nextLink' => self::NEXT_LINK,
        ])])->importPage($this->account);

        self::assertSame(GraphImportStep::More, $step);
        self::assertCount(1, $this->requests);

        $url = urldecode($this->requests[0]['url']);

        self::assertStringContainsString('/mailFolders/' . self::INBOX . '/messages/delta?', $url);
        self::assertStringContainsString('$orderby=receivedDateTime desc', $url);
        self::assertContains(
            sprintf('Prefer: IdType="ImmutableId", odata.maxpagesize=%d', GraphApiSyncer::IMPORT_PAGE_SIZE),
            $this->requests[0]['headers'],
        );

        self::assertSame([['a', 'b']], $this->batches());
        self::assertSame([GraphApiSyncer::IMPORT_QUEUE], $this->queues());

        self::assertSame(self::NEXT_LINK, $this->account->graphImport[0]['next']);
        self::assertSame([], $this->account->graphDeltaLinks, 'a folder is not followed until it has been read to its end');
    }

    public function testTheNextPageContinuesFromTheStoredLink(): void
    {
        $this->account->graphImport = [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => self::NEXT_LINK]];
        $this->em->flush();

        $this->syncer([$this->json(['value' => [], '@odata.nextLink' => self::NEXT_LINK . '3'])])
            ->importPage($this->account);

        self::assertSame(self::NEXT_LINK, $this->requests[0]['url']);
        self::assertSame(self::NEXT_LINK . '3', $this->account->graphImport[0]['next']);
    }

    public function testTheLastPageMakesTheFolderTheSyncsAndMovesOnToTheNext(): void
    {
        $this->account->graphImport = [
            ['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => self::NEXT_LINK],
            ['folder' => self::TRASH, 'since' => 1_800_000_000, 'next' => null],
        ];
        $this->em->flush();

        $step = $this->syncer([$this->json(['value' => [], '@odata.deltaLink' => self::DELTA_LINK])])
            ->importPage($this->account);

        self::assertSame(GraphImportStep::More, $step);
        self::assertSame([self::INBOX => self::DELTA_LINK], $this->account->graphDeltaLinks);
        self::assertSame([self::TRASH], array_column($this->account->graphImport, 'folder'));
    }

    public function testTheLastPageOfTheLastFolderFinishesTheImport(): void
    {
        $this->account->graphImport = [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => self::NEXT_LINK]];
        $this->em->flush();

        $step = $this->syncer([$this->json(['value' => [], '@odata.deltaLink' => self::DELTA_LINK])])
            ->importPage($this->account);

        self::assertSame(GraphImportStep::Finished, $step);
        self::assertFalse($this->account->needsGraphImport());
        self::assertTrue(self::getContainer()->get(InitialImportState::class)->isComplete($this->account));
    }

    public function testAnAccountWithFoldersStillToReadIsNotCalledImported(): void
    {
        $this->account->graphDeltaLinks = [self::INBOX => self::DELTA_LINK];
        $this->account->graphImport     = [['folder' => self::TRASH, 'since' => 1_800_000_000, 'next' => null]];
        $this->em->flush();

        self::assertFalse(self::getContainer()->get(InitialImportState::class)->isComplete($this->account));
    }

    public function testAMessageAlreadyStoredIsNotFetchedASecondTime(): void
    {
        $this->seedMessage('have-it');

        $this->account->graphImport = [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => null]];
        $this->em->flush();

        $this->syncer([$this->json([
            'value'            => [['id' => 'have-it', 'parentFolderId' => self::INBOX], ['id' => 'want-it', 'parentFolderId' => self::INBOX]],
            '@odata.deltaLink' => self::DELTA_LINK,
        ])])->importPage($this->account);

        self::assertSame([['want-it']], $this->batches());
    }

    public function testAFolderThatCannotBeReadGoesToTheBackRatherThanHoldingTheOthers(): void
    {
        $this->account->graphImport = [
            ['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => self::NEXT_LINK],
            ['folder' => self::TRASH, 'since' => 1_800_000_000, 'next' => null],
        ];
        $this->em->flush();

        $step = $this->syncer([new MockResponse('{"error":{"code":"ErrorInternalServerError"}}', ['http_code' => 500])])
            ->importPage($this->account);

        self::assertSame(GraphImportStep::Waiting, $step);
        self::assertSame([self::TRASH, self::INBOX], array_column($this->account->graphImport, 'folder'));
        self::assertSame(self::NEXT_LINK, $this->account->graphImport[1]['next'], 'and keeps its place for when it can');
    }

    public function testAnEnumerationTheServerHasForgottenStartsOver(): void
    {
        $this->account->graphImport = [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => self::NEXT_LINK]];
        $this->em->flush();

        $step = $this->syncer([new MockResponse('{"error":{"code":"SyncStateNotFound"}}', ['http_code' => 410])])
            ->importPage($this->account);

        self::assertSame(GraphImportStep::More, $step);
        self::assertSame(
            [['folder' => self::INBOX, 'since' => 1_800_000_000, 'next' => null]],
            $this->account->graphImport,
        );
    }

    // ── removals that cannot be judged yet ────────────────────────────────

    /**
     * Removed from the Inbox while the bin is still being read: it may be in
     * the bin. Erasing it now would delete a message that was only moved, and
     * forgetting it would leave the row of one that was deleted for good.
     */
    public function testARemovalIsKeptRatherThanActedOnWhileAFolderIsStillImporting(): void
    {
        $message = $this->seedMessage('moved-or-deleted');
        $id      = $message->id;

        $this->account->graphDeltaLinks = [self::INBOX => self::DELTA_LINK];
        $this->account->graphImport     = [['folder' => self::TRASH, 'since' => time() + 3600, 'next' => null]];
        $this->em->flush();

        $this->syncer([
            $this->json([
                'value'            => [['id' => 'moved-or-deleted', '@removed' => ['reason' => 'deleted']]],
                '@odata.deltaLink' => self::DELTA_LINK,
            ]),
            $this->json(['value' => []]),
        ])->sync($this->account, [self::INBOX, self::TRASH]);

        self::assertNotNull($this->em->find(Message::class, $id), 'not erased on half the evidence');
        self::assertSame(['moved-or-deleted'], $this->account->graphRemovals);
    }

    public function testAKeptRemovalIsActedOnOnceEveryFolderHasAnswered(): void
    {
        $message = $this->seedMessage('deleted', labelled: false);
        $id      = $message->id;

        $this->account->graphDeltaLinks = [self::INBOX => self::DELTA_LINK];
        $this->account->graphRemovals   = ['deleted'];
        $this->em->flush();

        $this->syncer([$this->json(['value' => [], '@odata.deltaLink' => self::DELTA_LINK])])
            ->sync($this->account, [self::INBOX]);

        $this->em->clear();

        self::assertNull($this->em->find(Message::class, $id));
        self::assertSame([], $this->em->find(Account::class, $this->account->id)->graphRemovals);
    }

    public function testAKeptRemovalThatTurnedUpInAFolderIsLeftAlone(): void
    {
        $message = $this->seedMessage('only-moved');
        $id      = $message->id;

        $this->account->graphDeltaLinks = [self::INBOX => self::DELTA_LINK];
        $this->account->graphRemovals   = ['only-moved'];
        $this->em->flush();

        $this->syncer([$this->json(['value' => [], '@odata.deltaLink' => self::DELTA_LINK])])
            ->sync($this->account, [self::INBOX]);

        $this->em->clear();

        self::assertNotNull($this->em->find(Message::class, $id));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * A syncer over a client that answers with the given responses in order
     * and records what it was asked, and a bus that records instead of
     * sending. One response too few fails the test by itself, which is the
     * point: a request nobody expected is the regression.
     *
     * @param list<MockResponse> $responses
     */
    private function syncer(array $responses): GraphApiSyncer
    {
        $this->requests   = [];
        $this->dispatched = new \ArrayObject();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return array_shift($responses) ?? throw new \LogicException('An unexpected request: ' . $url);
        });

        $bus = new class($this->dispatched) implements MessageBusInterface {
            /** @param \ArrayObject<int, Envelope> $dispatched */
            public function __construct(private \ArrayObject $dispatched)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);

                $this->dispatched->append($envelope);

                return $envelope;
            }
        };

        $container = self::getContainer();

        return new GraphApiSyncer(
            new GraphApiClient($http, $container->get('App\Service\OAuth\OAuthTokenManager')),
            $container->get('App\Service\Graph\GraphFolderResolver'),
            $container->get('App\Service\Graph\GraphLabelPolicy'),
            $container->get('App\Repository\Mail\MessageRepository'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            $bus,
            new \Psr\Log\NullLogger(),
            $container->get('App\Service\Mail\MessageEraser'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            new \App\Service\Mail\SyncOrigin(),
        );
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** @return list<list<string>> the ids of each batch sent to be fetched */
    private function batches(): array
    {
        return array_map(
            static function (Envelope $envelope): array {
                $message = $envelope->getMessage();

                self::assertInstanceOf(SyncGraphMessageBatchMessage::class, $message);

                return $message->graphIds;
            },
            $this->dispatched->getArrayCopy(),
        );
    }

    /** @return list<string|null> the queue each batch was sent to by name, null for the routing table's */
    private function queues(): array
    {
        return array_map(
            static fn (Envelope $envelope): ?string => $envelope->last(TransportNamesStamp::class)?->getTransportNames()[0],
            $this->dispatched->getArrayCopy(),
        );
    }

    private function seedMessage(string $graphId, bool $labelled = true): Message
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Stored already';
        $thread->normalizedSubject = 'stored already';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Stored already';
        $message->fromAddress    = 'sender@example.test';
        $message->graphId        = $graphId;
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->syncedAt       = new DateTimeImmutable();

        if (true === $labelled) {
            $message->addLabel(
                self::getContainer()->get('App\Service\Graph\GraphFolderResolver')->resolveFolder(self::INBOX, $this->account),
            );
        }

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedLabel(string $name, ?LabelRole $role, string $graphFolderId): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        $binding                = new LabelBinding();
        $binding->label         = $label;
        $binding->account       = $this->account;
        $binding->graphFolderId = $graphFolderId;

        $label->addBinding($binding);

        $this->em->persist($binding);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Graph fixture';
        $account->email          = 'graph@example.test';
        $account->username       = uniqid('graph-', true);
        $account->imapHost       = 'outlook.office365.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';

        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'graph-import-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Import';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
