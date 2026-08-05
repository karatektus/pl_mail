<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Google;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\Google\GoogleCalendarApiClient;
use App\Service\Calendar\Sync\Google\GoogleCalendarSyncDriver;
use App\Service\Calendar\Sync\Google\GoogleEventMapper;
use App\Service\Calendar\Sync\Google\GoogleRecurrenceMapper;
use App\Service\OAuth\OAuthTokenManager;
use App\Tests\Service\Calendar\RecordingLogger;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Google driver assembled over a scripted transport, plus the entities it
 * needs to be called at all.
 *
 * A helper class rather than an abstract test case, matching
 * FakeCalendarSyncDriver: four test classes need the same driver over different
 * scripts, and inheritance would make the setup something each of them
 * overrides a piece of rather than something they all state.
 *
 * The responses are replayed in the order they are given, and every one of them
 * remembers the request that consumed it — which is how a test asserts that
 * If-Match was actually sent, that the second page asked for a page token, and
 * that an update was a PATCH rather than a PUT. Those are claims about the
 * request, and a fixture that only scripted the response could not make them.
 *
 * Nothing here reaches the network: MockHttpClient answers from the script and
 * fails loudly if the driver asks for more requests than were queued.
 */
final class GoogleDriverFixture
{
    public readonly GoogleCalendarSyncDriver $driver;

    /** @var list<MockResponse> in the order the driver will consume them */
    public readonly array $responses;

    /**
     * Everything the driver said while it worked.
     *
     * A real recorder rather than a NullLogger, because one thing this driver
     * does is deliberately not visible in its answer: an instance the remote has
     * no resource for is skipped, and the log line is the only evidence that it
     * happened rather than the write silently going nowhere.
     */
    public readonly RecordingLogger $logger;

    /**
     * Kept so a test can count what was actually sent. Queuing a response the
     * driver never asks for is how "it did nothing" is asserted, and that is
     * invisible from the responses alone.
     */
    public readonly MockHttpClient $http;

    public function __construct(MockResponse ...$responses)
    {
        $this->responses = array_values($responses);
        $this->logger    = new RecordingLogger();
        $this->http      = new MockHttpClient($this->responses);

        $recurrence = new GoogleRecurrenceMapper(new RecurrenceRuleConverter());

        $this->driver = new GoogleCalendarSyncDriver(
            new GoogleCalendarApiClient($this->http, self::tokens()),
            new GoogleEventMapper($recurrence),
            $this->logger,
        );
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => array_merge(['content-type' => 'application/json'], $headers),
        ]);
    }

    /**
     * A Google error envelope, in the classic errors[].reason shape every
     * calendar endpoint still sends.
     *
     * @param array<string,string> $headers
     */
    public static function error(int $status, string $reason, string $message = 'Refused', array $headers = []): MockResponse
    {
        return self::json([
            'error' => [
                'code'    => $status,
                'errors'  => [['reason' => $reason, 'message' => $message]],
                'message' => $message,
            ],
        ], $status, $headers);
    }

    /** 204 with nothing in it, which is how a successful delete answers. */
    public static function empty(int $status = 204): MockResponse
    {
        return new MockResponse('', ['http_code' => $status]);
    }

    public static function account(): Account
    {
        $account                = new Account();
        $account->usr           = new User();
        $account->email         = 'someone@example.com';
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Google->value;

        return $account;
    }

    public static function calendar(?Account $account = null, string $timeZone = 'Europe/Berlin'): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = new User();
        $calendar->account  = $account ?? self::account();
        $calendar->role     = CalendarRole::Remote;
        $calendar->remoteId = 'primary';
        $calendar->timeZone = $timeZone;

        return $calendar;
    }

    /**
     * @param array<string,mixed> $jscalendar
     */
    public static function event(
        string  $uid = 'uid-1',
        ?string $remoteId = null,
        ?string $etag = null,
        array   $jscalendar = [],
        bool    $isAllDay = false,
    ): CalendarEvent {
        $event             = new CalendarEvent();
        $event->uid        = $uid;
        $event->title      = 'Standup';
        $event->location   = 'Room 3';
        $event->startsAt   = new DateTimeImmutable('2026-08-04 08:00:00', new DateTimeZone('UTC'));
        $event->endsAt     = new DateTimeImmutable('2026-08-04 08:30:00', new DateTimeZone('UTC'));
        $event->timeZone   = 'Europe/Berlin';
        $event->isAllDay   = $isAllDay;
        $event->status     = EventStatus::Confirmed;
        $event->remoteId   = $remoteId;
        $event->remoteEtag = $etag;
        $event->jscalendar = $jscalendar;

        return $event;
    }

    public function url(int $index): string
    {
        return $this->responses[$index]->getRequestUrl();
    }

    public function method(int $index): string
    {
        return $this->responses[$index]->getRequestMethod();
    }

    /**
     * The decoded JSON body the driver sent.
     *
     * @return array<string,mixed>
     */
    public function payload(int $index): array
    {
        $body = $this->responses[$index]->getRequestOptions()['body'] ?? '';

        if (false === is_string($body) || '' === $body) {
            return [];
        }

        $decoded = json_decode($body, true);

        return true === is_array($decoded) ? $decoded : [];
    }

    /**
     * One request header, looked up case-insensitively because the transport
     * normalises the case and a test asserting on "If-Match" should not depend
     * on which way it went.
     */
    public function header(int $index, string $name): ?string
    {
        $headers = $this->responses[$index]->getRequestOptions()['headers'] ?? [];

        if (false === is_array($headers)) {
            return null;
        }

        foreach ($headers as $key => $value) {
            $line = true === is_int($key) ? (string) $value : $key . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value);

            if (true === str_starts_with(mb_strtolower($line), mb_strtolower($name) . ':')) {
                return trim(mb_substr($line, mb_strlen($name) + 1));
            }
        }

        return null;
    }

    /**
     * A token manager that answers without a network or a database.
     *
     * An anonymous subclass rather than a PHPUnit stub so this fixture is
     * usable from a plain helper class: createStub() lives on TestCase, and
     * threading a doubled token manager in from four test classes would put the
     * one uninteresting collaborator in every one of their signatures.
     */
    private static function tokens(): OAuthTokenManager
    {
        return new class extends OAuthTokenManager {
            public function __construct()
            {
                // Deliberately does not call the parent: it wants a provider
                // factory and an entity manager, and nothing on this path ever
                // reads either.
            }

            public function getValidAccessToken(Account $account): string
            {
                return 'test-token';
            }
        };
    }
}
