<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\GraphThrottledException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Label\LabelBinding\LabelBindingRepository;
use App\Service\Graph\GraphCategoryColorMapper;
use App\Service\Graph\GraphCategorySyncer;
use App\Service\Graph\GraphFolderSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GraphApiClient;
use App\Service\OAuth\OAuthTokenManager;
use App\Tests\Support\Log\RecordingLogger;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A personal Outlook mailbox that is idle and is still rate-limited about once
 * an hour (#524).
 *
 * Three things about that, against mocked Graph HTTP:
 *
 *  - Most of what an idle sync sent was the same thirteen "which folder is the
 *    Inbox" lookups every time. They are kept on the account now, and asked
 *    again only when the folder list has changed.
 *  - A 429 during those lookups was read as "this mailbox has no such folder".
 *  - A 429 came out as an ERROR with a stack trace and `"error": ""`, because
 *    Outlook sends it with an empty body and the body was the whole message.
 */
final class GraphThrottleAndFolderLookupTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    /** @var list<string> method and path of every request, in order */
    private array $requests = [];

    /** @var list<array<string,mixed>> what the folder list answers */
    private array $folders = [];

    /** @var array<string,string> well-known names this mailbox has, => id */
    private array $wellKnown = [];

    /** The well-known name whose lookup answers 429, if any. */
    private ?string $throttled = null;

    private bool $categoriesThrottled = false;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->seed();

        $this->folders = [
            ['id' => 'id-inbox', 'displayName' => 'Inbox', 'parentFolderId' => 'id-root', 'totalItemCount' => 3],
            ['id' => 'id-sent', 'displayName' => 'Sent Items', 'parentFolderId' => 'id-root', 'totalItemCount' => 1],
            ['id' => 'id-work', 'displayName' => 'Work', 'parentFolderId' => 'id-root', 'totalItemCount' => 2],
        ];

        $this->wellKnown = [
            'msgfolderroot' => 'id-root',
            'inbox'         => 'id-inbox',
            'sentitems'     => 'id-sent',
        ];
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFolderLookupsAreNotRepeatedWhileTheFolderListStaysTheSame(): void
    {
        $this->folderSyncer()->sync($this->account);

        self::assertSame(13, $this->lookups(), 'the first sync asks for every well-known name');

        $this->requests = [];
        $this->folderSyncer()->sync($this->account);

        self::assertSame(0, $this->lookups(), 'the second, with the same folders, asks for none');
        self::assertSame(['GET /me/mailFolders/delta'], $this->requests, 'only the folder list itself');
    }

    /**
     * The answers must still be used, not just kept: the Inbox found on the
     * first sync is still the Inbox on the second, not a custom "Inbox".
     */
    public function testTheKeptAnswerStillMakesTheInboxTheInbox(): void
    {
        $syncable = $this->folderSyncer()->sync($this->account);
        $again    = $this->folderSyncer()->sync($this->account);

        self::assertSame($syncable, $again);
        self::assertContains('id-inbox', $again);
        self::assertSame($this->wellKnown, $this->account->graphWellKnownFolders['folders'] ?? null);
    }

    /**
     * Exchange can give a system folder a new id. That changes the folder
     * list, which is read every sync, so the lookups run again in the same
     * sync that first sees it.
     */
    public function testAChangedFolderListAsksAgainInTheSameSync(): void
    {
        $this->folderSyncer()->sync($this->account);

        $this->folders[0]['id']   = 'id-inbox-rotated';
        $this->wellKnown['inbox'] = 'id-inbox-rotated';
        $this->requests           = [];

        $syncable = $this->folderSyncer()->sync($this->account);

        self::assertSame(13, $this->lookups());
        self::assertContains('id-inbox-rotated', $syncable);
        self::assertSame('id-inbox-rotated', $this->account->graphWellKnownFolders['folders']['inbox'] ?? null);
    }

    public function testAKeptAnswerOlderThanADayIsAskedAgain(): void
    {
        $this->folderSyncer()->sync($this->account);

        $kept       = $this->account->graphWellKnownFolders;
        self::assertNotNull($kept);
        $kept['at'] = time() - 86_401;
        $this->account->graphWellKnownFolders = $kept;

        $this->requests = [];
        $this->folderSyncer()->sync($this->account);

        self::assertSame(13, $this->lookups());
    }

    /**
     * A 429 on `inbox` used to be swallowed as "no such folder", and the real
     * Inbox was then filed as a custom folder for that run. Now it stops the
     * folder sync, like a throttled folder list always did, and nothing is
     * kept.
     */
    public function testAThrottledLookupIsNotTakenForAMissingFolder(): void
    {
        $this->throttled = 'inbox';

        try {
            $this->folderSyncer()->sync($this->account);
            self::fail('a throttled lookup has to stop the folder sync');
        } catch (GraphThrottledException) {
        }

        self::assertNull($this->account->graphWellKnownFolders, 'a map with a gap is not kept');
    }

    /**
     * Not found is still not found: a mailbox without an Archive syncs.
     */
    public function testAFolderTheMailboxDoesNotHaveIsStillSkipped(): void
    {
        $this->folderSyncer()->sync($this->account);

        self::assertArrayNotHasKey('archive', $this->account->graphWellKnownFolders['folders'] ?? []);
    }

    public function testAThrottledCategoryListIsOneWarningThatSaysWhatHappened(): void
    {
        $this->categoriesThrottled = true;
        $logger                    = new RecordingLogger();

        $container = self::getContainer();
        $syncer    = new GraphCategorySyncer(
            $this->client(),
            $container->get(LabelResolver::class),
            $container->get(GraphCategoryColorMapper::class),
            $this->em,
            $logger,
        );

        $syncer->sync($this->account);

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertArrayNotHasKey('exception', $logger->records[0]['context'], 'no stack trace for a rate limit');
        self::assertSame(
            'Graph answered 429 (throttled), retry after 12 s, request-id req-123',
            $logger->records[0]['context']['error'],
            'an empty body still says what happened',
        );
        self::assertSame(12, $logger->records[0]['context']['retryAfter']);
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function lookups(): int
    {
        return count(array_filter(
            $this->requests,
            static fn (string $request): bool => 1 === preg_match('#^GET /me/mailFolders/(?!delta$)[a-z]+$#', $request),
        ));
    }

    private function folderSyncer(): GraphFolderSyncer
    {
        $container = self::getContainer();

        return new GraphFolderSyncer(
            $this->client(),
            $container->get(LabelResolver::class),
            $container->get(LabelBindingRepository::class),
            $this->em,
            new NullLogger(),
        );
    }

    private function client(): GraphApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $path = str_replace('/v1.0', '', (string) (parse_url($url, PHP_URL_PATH) ?? ''));

            $this->requests[] = $method . ' ' . $path;

            if (str_ends_with($path, '/mailFolders/delta')) {
                return $this->json(['value' => $this->folders, '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/delta?$deltatoken=x']);
            }

            if (str_ends_with($path, '/outlook/masterCategories') && true === $this->categoriesThrottled) {
                return $this->throttle();
            }

            if (str_ends_with($path, '/outlook/masterCategories')) {
                return $this->json(['value' => []]);
            }

            if (1 === preg_match('#/mailFolders/([a-z]+)$#', $path, $match)) {
                if ($match[1] === $this->throttled) {
                    return $this->throttle();
                }

                return array_key_exists($match[1], $this->wellKnown)
                    ? $this->json(['id' => $this->wellKnown[$match[1]], 'displayName' => $match[1]])
                    : $this->json(['error' => ['code' => 'ErrorItemNotFound']], 404);
            }

            return $this->json(['value' => []]);
        });

        return new GraphApiClient($http, self::getContainer()->get(OAuthTokenManager::class));
    }

    /** What a throttled personal mailbox sends: no body at all. */
    private function throttle(): MockResponse
    {
        return new MockResponse('', [
            'http_code'        => 429,
            'response_headers' => ['retry-after' => '12', 'request-id' => 'req-123'],
        ]);
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

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'graph-throttle-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Throttle';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->email          = 'graph-throttle-fixture@example.test';
        $this->account->username       = 'graph-throttle-fixture@example.test';
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
        // A token that is good for a while, or the client goes off to refresh
        // one before the first request and every assertion sees nothing.
        $this->account->oauthAccessToken = 'test-access-token';
        $this->account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $this->em->persist($this->account);

        $this->em->flush();

        $user->addAccount($this->account);

        $this->em->flush();
    }
}
