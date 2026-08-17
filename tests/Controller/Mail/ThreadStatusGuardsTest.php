<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Repository\User\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the six status routes refuse.
 *
 * All of them mutate mail, all of them resolve through
 * ThreadStatusController::resolveMessages(), and none of them used to fail
 * closed. Three separate holes met in that one helper:
 *
 *  - An id matching no row became `[null]`, which reached
 *    ThreadStatusUpdater::accountOf() — parameter not nullable — and died with
 *    a TypeError. So a missing id answered 500 and a real id belonging to
 *    somebody else answered 403, and telling the two apart was an existence
 *    check anybody with a session could run against every id on the server.
 *  - A `{type}` that was neither `message` nor `thread` left the list empty,
 *    which walked the ownership loop without ever entering it and only failed
 *    afterwards on `$messages[0]`.
 *  - Nothing checked a CSRF token, although every Stimulus caller had been
 *    sending one in X-CSRF-Token all along.
 *
 * The oracle is what these pin hardest: a caller must not be able to tell
 * "no such thread" from "not yours" by the status code alone.
 */
final class ThreadStatusGuardsTest extends WebTestCase
{
    private const string USER_EMAIL = 'e2e@plmail.test';

    /**
     * An id far past anything the fixtures create, so the row cannot exist.
     */
    private const int ABSENT_ID = 999_999_999;

    /**
     * @return iterable<string, array{string}>
     */
    public static function routes(): iterable
    {
        yield 'star'    => ['star'];
        yield 'archive' => ['archive'];
        yield 'trash'   => ['trash'];
        yield 'label'   => ['label'];
        yield 'snooze'  => ['snooze'];
        yield 'read'    => ['read'];
    }

    /**
     * The regression proper: 500 was the tell.
     */
    #[DataProvider('routes')]
    public function testAMissingIdIsNotAServerError(string $action): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            sprintf('/status/thread/%d/%s', self::ABSENT_ID, $action),
            server: $this->jsonPost($client),
            content: '{}',
        );

        self::assertSame(
            404,
            $client->getResponse()->getStatusCode(),
            sprintf('/status/thread/{id}/%s leaked whether the id exists', $action),
        );
    }

    #[DataProvider('routes')]
    public function testAMissingMessageIdIsNotAServerError(string $action): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            sprintf('/status/message/%d/%s', self::ABSENT_ID, $action),
            server: $this->jsonPost($client),
            content: '{}',
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * `{type}` is constrained by the route now, so an unknown one never reaches
     * the controller — but it must not reach it as a 500 either way.
     */
    public function testAnUnknownTypeIsRejected(): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            sprintf('/status/mailbox/%d/star', self::ABSENT_ID),
            server: $this->jsonPost($client),
            content: '{}',
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * A non-numeric id used to be cast on its way into the action; the route
     * requirement is what stops it now.
     */
    public function testANonNumericIdIsRejected(): void
    {
        $client = $this->signedIn();

        $client->request('POST', '/status/thread/not-a-number/star', server: $this->jsonPost($client), content: '{}');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ── CSRF ──────────────────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testATokenlessPostIsRefused(string $action): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            sprintf('/status/thread/%d/%s', self::ABSENT_ID, $action),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertSame(
            403,
            $client->getResponse()->getStatusCode(),
            sprintf('/status/thread/{id}/%s accepted a request with no token', $action),
        );
    }

    #[DataProvider('routes')]
    public function testAForgedTokenIsRefused(string $action): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            sprintf('/status/thread/%d/%s', self::ABSENT_ID, $action),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'nonsense'],
            content: '{}',
        );

        self::assertSame(
            403,
            $client->getResponse()->getStatusCode(),
            sprintf('/status/thread/{id}/%s accepted a forged token', $action),
        );
    }

    /**
     * Asserted against an id that does not exist, so the 403 can only be the
     * token check: it has to run BEFORE the lookup, or a forged request would
     * still get to ask whether a row is there.
     */
    public function testTheTokenIsCheckedBeforeTheLookup(): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            sprintf('/status/thread/%d/star', self::ABSENT_ID),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The `ajax` token, read the way the real callers read it.
     *
     * Scraped from the layout's `csrf-token` meta tag rather than minted from
     * the token manager: the manager stores session-backed tokens and there is
     * no session until a request has been made, so asking it first throws
     * SessionNotFoundException. Rendering a page is also the honest version of
     * what the Stimulus controllers do — they read this same tag — so a change
     * that broke the tag would fail here instead of passing against a token the
     * browser never sees.
     *
     * @return array<string, string>
     */
    private function jsonPost(KernelBrowser $client): array
    {
        $crawler = $client->request('GET', '/mail/inbox');

        return [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => (string) $crawler->filter('meta[name="csrf-token"]')->attr('content'),
        ];
    }

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::USER_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
