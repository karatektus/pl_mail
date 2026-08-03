<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Exception\GmailPermanentException;
use App\Domain\Exception\GmailThrottledException;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyGmailLabelsHandler;
use App\Infrastructure\Messaging\Message\ApplyGmailLabelsMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailLabelColorMapper;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GmailApiClient;
use App\Service\OAuth\OAuthTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What an outgoing label push does when Gmail refuses it.
 *
 * The bug behind these tests: batchModify() never read its response, so it
 * could not throw, so the handler's catch was dead code. Archiving a thread
 * while Gmail was rate-limiting removed it locally, never told Gmail, and
 * logged nothing — and because Gmail's history feed only reports what happened
 * inside the mailbox, no later sync went looking for the push that never
 * landed.
 *
 * The split these pin is the point of the fix: a quota rejection has to leave
 * the handler so the transport redelivers it, and a refusal that will answer
 * the same way forever has to stop here instead.
 *
 * GmailApiClient is final, so it is built for real against a MockHttpClient
 * rather than doubled, matching GmailApiClientFailureTest.
 */
final class ApplyGmailLabelsHandlerTest extends TestCase
{
    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->logged = [];
    }

    public function testAThrottledPushEscapesTheHandlerSoMessengerRetriesIt(): void
    {
        $handler = $this->handler(403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'userRateLimitExceeded']],
                'message' => 'User Rate Limit Exceeded',
            ],
        ]);

        // Caught and logged, the archive would be applied locally and nowhere
        // else. Propagating is what buys the redelivery.
        $this->expectException(GmailThrottledException::class);

        $handler(new ApplyGmailLabelsMessage(1, [10], [], ['INBOX']));
    }

    public function testTheRetryDelayIsTheOneGmailAsksFor(): void
    {
        $handler = $this->handler(
            429,
            ['error' => ['code' => 429, 'message' => 'Too Many Requests']],
            ['retry-after' => '120'],
        );

        try {
            $handler(new ApplyGmailLabelsMessage(1, [10], ['STARRED'], []));
            self::fail('a throttled push must not be swallowed');
        } catch (GmailThrottledException $e) {
            self::assertSame(120000, $e->getRetryDelay());
        }
    }

    public function testAPermanentRefusalIsLoggedAndStopsHere(): void
    {
        // Redelivering a scope the grant never included just repeats the same
        // rejection and buries the log line that explains it.
        $handler = $this->handler(403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'insufficientPermissions']],
                'message' => 'Request had insufficient authentication scopes.',
            ],
        ]);

        $handler(new ApplyGmailLabelsMessage(1, [10], ['STARRED'], []));

        self::assertSame(['error'], array_column($this->logged, 'level'));
        self::assertStringContainsString('refused permanently', $this->logged[0]['message']);
    }

    public function testAnUnclassifiedFailureAlsoEscapesRatherThanVanishing(): void
    {
        // A 500 is Gmail's problem, not the grant's; swallowing it loses the
        // push for a failure that would very likely succeed on the next try.
        $handler = $this->handler(500, ['error' => ['code' => 500, 'message' => 'Backend Error']]);

        $this->expectException(\App\Domain\Exception\GmailApiException::class);

        $handler(new ApplyGmailLabelsMessage(1, [10], [], ['UNREAD']));
    }

    public function testASuccessfulPushLogsNothing(): void
    {
        $handler = $this->handler(204, null);

        $handler(new ApplyGmailLabelsMessage(1, [10], ['STARRED'], ['INBOX']));

        self::assertSame([], $this->logged);
    }

    public function testAPushWithNothingToSayNeverReachesGmail(): void
    {
        // No label ids on either side, so the failing response is never
        // requested — proof the handler stops before the call rather than
        // after it.
        $handler = $this->handler(403, [
            'error' => ['code' => 403, 'errors' => [['reason' => 'rateLimitExceeded']]],
        ]);

        $handler(new ApplyGmailLabelsMessage(1, [10], [], []));

        self::assertSame([], $this->logged);
    }

    public function testAMessageWithNoGmailIdIsNotPushed(): void
    {
        $handler = $this->handler(
            403,
            ['error' => ['code' => 403, 'errors' => [['reason' => 'rateLimitExceeded']]]],
            [],
            gmailId: null,
        );

        $handler(new ApplyGmailLabelsMessage(1, [10], ['STARRED'], []));

        self::assertSame([], $this->logged);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * A handler whose Gmail client answers every request with $status.
     *
     * add/remove carry Gmail system label ids throughout, which the handler
     * uses verbatim — so nothing here touches label resolution, and the only
     * call under test is the batchModify.
     *
     * @param array<string,mixed>|null $json    decoded body, or null for none
     * @param array<string,string>     $headers
     */
    private function handler(int $status, ?array $json, array $headers = [], ?string $gmailId = 'gmail-1'): ApplyGmailLabelsHandler
    {
        $http = new MockHttpClient(new MockResponse(
            null !== $json ? json_encode($json, JSON_THROW_ON_ERROR) : '',
            [
                'http_code'        => $status,
                'response_headers' => array_merge(['content-type' => 'application/json'], $headers),
            ],
        ));

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('test-token');

        $account = new Account();
        $account->setUsr(new User());

        $accountRepository = $this->createStub(AccountRepository::class);
        $accountRepository->method('find')->willReturn($account);

        $entity = new Message();
        $entity->gmailId = $gmailId;

        $messageRepository = $this->createStub(MessageRepository::class);
        $messageRepository->method('findBy')->willReturn([$entity]);

        return new ApplyGmailLabelsHandler(
            $accountRepository,
            new GmailLabelColorMapper(),
            $messageRepository,
            $this->createStub(LabelRepository::class),
            new GmailApiClient($http, $tokenManager),
            // final, so it cannot be doubled — and it is never reached on this
            // path, because system label ids skip resolution entirely. An
            // instance without its constructor fails loudly if that ever stops
            // being true, which is the behaviour worth having.
            (new \ReflectionClass(LabelResolver::class))->newInstanceWithoutConstructor(),
            $this->createStub(EntityManagerInterface::class),
            $this->logger(),
        );
    }

    private function logger(): LoggerInterface
    {
        return new class($this->logged) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $logged */
            public function __construct(public array &$logged) {}

            /** @param array<mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }
}
