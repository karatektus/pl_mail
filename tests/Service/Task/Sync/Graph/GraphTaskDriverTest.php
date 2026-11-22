<?php

declare(strict_types=1);

namespace App\Tests\Service\Task\Sync\Graph;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Task\TaskStatus;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Mail\Account;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use App\Service\OAuth\OAuthTokenManager;
use App\Service\Task\Sync\Graph\GraphTaskDriver;
use App\Service\Task\Sync\Graph\GraphTaskMapper;
use App\Service\User\UserTimezoneResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Against a MockHttpClient, for the reason GraphCalendarSyncDriverTest gives:
 * what is worth covering is what the driver asks for and how it reads the answer,
 * and the failures nobody can produce on demand from a real account.
 */
final class GraphTaskDriverTest extends TestCase
{
    public function testOnlyAMicrosoftAccountWhoseGrantDoesNotLackTasksIsClaimed(): void
    {
        $driver = $this->driver(new MockHttpClient([]));

        self::assertTrue($driver->supports(CalendarSource::ofAccount($this->account())));

        $before                     = $this->account();
        $before->oauthGrantedScopes = 'Mail.ReadWrite Calendars.ReadWrite Contacts.ReadWrite User.Read';
        self::assertFalse($driver->supports(CalendarSource::ofAccount($before)), 'a grant known to lack Tasks.ReadWrite');

        $granted                     = $this->account();
        $granted->oauthGrantedScopes = 'Mail.ReadWrite Calendars.ReadWrite Contacts.ReadWrite Tasks.ReadWrite User.Read';
        self::assertTrue($driver->supports(CalendarSource::ofAccount($granted)));

        self::assertFalse($driver->supports(CalendarSource::ofAccount($this->account(MailProvider::Google))));
    }

    public function testDiscoveryOffersTheListsButNotFlaggedMailAndMarksTheDefault(): void
    {
        $http = new MockHttpClient([$this->json(['value' => [
            ['id' => 'L1', 'displayName' => 'Tasks', 'wellknownListName' => 'defaultList'],
            ['id' => 'L2', 'displayName' => 'Groceries', 'wellknownListName' => 'none'],
            ['id' => 'L3', 'displayName' => 'Flagged email', 'wellknownListName' => 'flaggedEmails'],
        ]])]);

        $lists = $this->driver($http)->discover(CalendarSource::ofAccount($this->account()));

        self::assertSame(['L1', 'L2'], array_map(static fn ($list) => $list->remoteId, $lists));
        self::assertTrue($lists[0]->isDefault);
        self::assertFalse($lists[1]->isDefault);
    }

    /** Regression guard: storing a nextLink as the token resumes mid-window and reads a page as the whole. */
    public function testAFirstPullAsksForStepsFollowsEveryPageAndKeepsTheDeltaLink(): void
    {
        $requests = [];
        $http     = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return 1 === count($requests)
                ? $this->json(['value' => [['id' => 'T1', 'title' => 'One']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next'])
                : $this->json(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?token=1']);
        });

        $changes = $this->driver($http)->pull($this->list(), null);

        self::assertStringContainsString('/me/todo/lists/L1/tasks/delta', $requests[0]);
        self::assertStringContainsString('expand=checklistItems', urldecode($requests[0]));
        self::assertSame('GET https://graph.microsoft.com/v1.0/next', $requests[1]);
        self::assertSame('https://graph.microsoft.com/v1.0/delta?token=1', $changes->nextSyncToken);
        self::assertSame('T1', $changes->tasks[0]->remoteId);
    }

    public function testAResumedPullPresentsTheStoredLinkAndNothingElse(): void
    {
        $asked = null;
        $http  = new MockHttpClient(function (string $method, string $url) use (&$asked): MockResponse {
            $asked = $url;

            return $this->json(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?token=2']);
        });

        $this->driver($http)->pull($this->list(), 'https://graph.microsoft.com/v1.0/delta?token=1');

        self::assertSame('https://graph.microsoft.com/v1.0/delta?token=1', $asked);
    }

    public function testADeadDeltaLinkAsksForAFullRead(): void
    {
        $http = new MockHttpClient([$this->json(['error' => ['code' => 'syncStateNotFound']], 410)]);

        $this->expectException(CalendarResyncRequiredException::class);

        $this->driver($http)->pull($this->list(), 'https://graph.microsoft.com/v1.0/delta?token=old');
    }

    public function testARefusedGrantSaysToReconnect(): void
    {
        $http = new MockHttpClient([$this->json(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403)]);

        $this->expectException(CalendarSyncPermanentException::class);
        $this->expectExceptionMessage('Reconnect the account and allow tasks');

        $this->driver($http)->pull($this->list(), null);
    }

    /** No etag: a completed repeating task comes back open, and the next pull must not skip it. */
    public function testACreateStoresWhatCameBackAndNoEtag(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = [$method, $url, json_decode((string) $options['body'], true)];

            return $this->json(['id' => 'NEW', '@odata.etag' => 'W/"1"', 'title' => 'Mine'], 201);
        });

        $task        = new Task();
        $task->title = 'Mine';

        $result = $this->driver($http)->push($this->list(), $task);

        self::assertSame('POST', $sent[0]);
        self::assertStringEndsWith('/me/todo/lists/L1/tasks', $sent[1]);
        self::assertSame('Mine', $sent[2]['title']);
        self::assertSame('NEW', $result->remoteId);
        self::assertNull($result->etag);
        self::assertSame('Mine', json_decode((string) $result->payload, true)['title']);
    }

    public function testAnUnchangedTaskIsNotSentAtAll(): void
    {
        $task                = new Task();
        $task->title         = 'Same';
        $task->remoteId      = 'T1';
        $task->remotePayload = json_encode(['id' => 'T1', 'title' => 'Same'], JSON_THROW_ON_ERROR);

        $result = $this->driver(new MockHttpClient([]))->push($this->list(), $task);

        self::assertSame('T1', $result->remoteId);
    }

    public function testAStepIsAChecklistItemOfItsTaskAndIsNamedByBoth(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = [$method, $url, $options['body'] ?? null];

            return 'DELETE' === $method
                ? new MockResponse('', ['http_code' => 404])
                : $this->json(['id' => 'C1', 'displayName' => 'Step', 'isChecked' => true], 201);
        });

        $parent           = new Task();
        $parent->remoteId = 'T1';

        $step         = new Task();
        $step->title  = 'Step';
        $step->parent = $parent;
        $step->status = TaskStatus::Completed;

        $driver = $this->driver($http);
        $result = $driver->push($this->list(), $step);

        self::assertSame('POST', $sent[0][0]);
        self::assertStringEndsWith('/tasks/T1/checklistItems', $sent[0][1]);
        self::assertSame(['displayName' => 'Step', 'isChecked' => true], json_decode((string) $sent[0][2], true));
        self::assertSame('T1/C1', $result->remoteId);

        // Already gone with its task is gone, as the interface asks.
        $step->remoteId = 'T1/C1';
        $driver->delete($this->list(), $step);

        self::assertSame('DELETE', $sent[1][0]);
        self::assertStringEndsWith('/tasks/T1/checklistItems/C1', $sent[1][1]);
    }

    private function driver(MockHttpClient $http): GraphTaskDriver
    {
        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        return new GraphTaskDriver(
            $http,
            $tokens,
            new GraphTaskMapper(new GraphRecurrenceMapper(), new RecurrenceRuleConverter()),
            new UserTimezoneResolver('Europe/Berlin'),
            new MockClock('2026-11-22 10:00:00'),
        );
    }

    /** @param array<string,mixed> $body */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function account(MailProvider $provider = MailProvider::Microsoft): Account
    {
        $account                = new Account();
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = $provider->value;

        return $account;
    }

    private function list(): TaskList
    {
        $list           = new TaskList();
        $list->usr      = new User();
        $list->account  = $this->account();
        $list->remoteId = 'L1';

        return $list;
    }
}
