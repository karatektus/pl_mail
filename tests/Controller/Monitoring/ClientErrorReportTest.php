<?php

declare(strict_types=1);

namespace App\Tests\Controller\Monitoring;

use App\Entity\Monitoring\ClientError;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the browser-error endpoint keeps, and what it refuses.
 *
 * The refusals are the interesting half and the reason this feature needed
 * writing carefully. Most of what `window.onerror` sees belongs to somebody
 * else — extensions inject scripts into the page and those scripts throw — and
 * a panel that accepted all of it would fill with other people's bugs, which is
 * the same as not having a panel. The filter lives in two places on purpose,
 * the browser and the server, because the browser half is part of the code
 * being reported on.
 *
 * The grouping is the other claim. A broken line in a Stimulus controller runs
 * on every page load for every user, so a row has to be a distinct fault with a
 * count rather than one row per occurrence — otherwise one bug is four hundred
 * rows by teatime.
 */
final class ClientErrorReportTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** A real fault from one of our own scripts is kept, with its stack. */
    public function testAnErrorFromOurOwnScriptIsRecorded(): void
    {
        $client  = $this->signedIn();
        $message = 'Cannot read properties of undefined (reading ' . uniqid('', true) . ')';

        $this->post($client, [
            'kind'    => 'error',
            'message' => $message,
            'source'  => 'http://localhost/assets/controllers/mail/thread_controller.js',
            'line'    => 42,
            'column'  => 7,
            'stack'   => 'TypeError\n    at http://localhost/assets/controllers/mail/thread_controller.js:42:7',
            'url'     => 'http://localhost/mail/inbox',
        ]);

        self::assertResponseStatusCodeSame(204);

        $row = $this->rowFor($message);

        self::assertNotNull($row, 'a fault in our own script was thrown away');
        self::assertSame(42, $row['line']);
        self::assertSame(1, $row['occurrences']);
        self::assertStringContainsString('thread_controller.js', (string) $row['stack']);
    }

    /**
     * The same fault twice is one row and two occurrences.
     *
     * This is the whole reason the table is not a log: without it, one broken
     * line in code that runs on every page is a list nobody can read past.
     */
    public function testTheSameFaultIsCountedRatherThanListedTwice(): void
    {
        $client  = $this->signedIn();
        $message = 'undefined is not a function ' . uniqid('', true);

        $report = [
            'kind'    => 'error',
            'message' => $message,
            'source'  => 'http://localhost/assets/app.js',
            'line'    => 9,
            'column'  => 1,
            'stack'   => 'at http://localhost/assets/app.js:9:1',
            'url'     => 'http://localhost/mail/inbox',
        ];

        $this->post($client, $report);
        $this->post($client, [...$report, 'url' => 'http://localhost/calendar']);

        $row = $this->rowFor($message);

        self::assertNotNull($row);
        self::assertSame(2, $row['occurrences'], 'the second sighting made a second row');
        // The newest page rather than the first: the question this answers is
        // "can I still reproduce it", and the latest answer is the useful one.
        self::assertSame('http://localhost/calendar', $row['url']);
        self::assertSame(1, $this->rowsFor($message), 'one fault produced more than one row');
    }

    /**
     * An extension's script is not ours and is refused.
     *
     * The console screenshot that prompted this feature was exactly this: an
     * injected metrics script throwing inside `requestIdleCallback`, with
     * nothing to do with plMail.
     */
    public function testAnErrorFromAnInjectedScriptIsRefused(): void
    {
        $client  = $this->signedIn();
        $message = 'Cannot read properties of undefined (reading startTime) ' . uniqid('', true);

        // No source at all, which is what an eval'd or injected script reports —
        // Chrome shows it as `VM947` — and a stack that never mentions us.
        $this->post($client, [
            'kind'    => 'error',
            'message' => $message,
            'source'  => null,
            'stack'   => 'at et.reportAllChanges (<anonymous>:2:19429)',
            'url'     => 'http://localhost/mail/inbox',
        ]);

        self::assertResponseStatusCodeSame(204, 'a refused report must look exactly like an accepted one');
        self::assertNull($this->rowFor($message), 'an extension\'s error reached the panel');
    }

    /** The opaque cross-origin message carries nothing anybody could act on. */
    public function testTheOpaqueCrossOriginMessageIsRefused(): void
    {
        $client = $this->signedIn();

        $this->post($client, [
            'kind'    => 'error',
            'message' => 'Script error.',
            'source'  => 'http://localhost/assets/app.js',
            'url'     => 'http://localhost/mail/inbox',
        ]);

        self::assertNull($this->rowFor('Script error.'));
    }

    /**
     * Without a token the endpoint is writable by anything that finds it.
     *
     * What a forged request achieves here is only a junk row — but a junk row
     * in the panel an administrator reads to decide what is broken.
     */
    public function testAReportWithoutACsrfTokenIsRefused(): void
    {
        $client  = $this->signedIn();
        $message = 'no token ' . uniqid('', true);

        $client->request(
            'POST',
            '/client-error',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'kind'    => 'error',
                'message' => $message,
                'source'  => 'http://localhost/assets/app.js',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->rowFor($message));
    }

    /**
     * A CSP report is exempt from the same-origin rule, and has to be.
     *
     * The browser sends it about a resource it *refused* to load, so the URI in
     * it is by definition not ours — and the report is still entirely about
     * this page's own policy.
     */
    public function testACspViolationIsRecordedWithoutASession(): void
    {
        $client = $this->boot();
        $marker = 'https://tracker.example/' . uniqid('', true) . '.js';

        $client->request(
            'POST',
            '/csp-report',
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: (string) json_encode([
                'csp-report' => [
                    'document-uri'       => 'http://localhost/mail/inbox',
                    'violated-directive' => 'script-src',
                    'blocked-uri'        => $marker,
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204, 'the browser cannot send a token, so it must not need one');

        $row = $this->connection->fetchAssociative(
            'SELECT kind, message FROM client_error WHERE message LIKE ?',
            ['%' . $marker . '%'],
        );

        self::assertIsArray($row, 'the CSP violation was not recorded');
        self::assertSame(ClientError::KIND_CSP, $row['kind']);
        self::assertStringContainsString('script-src', (string) $row['message']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $report */
    private function post(KernelBrowser $client, array $report): void
    {
        $client->request(
            'POST',
            '/client-error',
            server: [
                'CONTENT_TYPE'        => 'application/json',
                'HTTP_X_CSRF_TOKEN'   => $this->token($client),
                'HTTP_USER_AGENT'     => 'Mozilla/5.0 (X11; Linux x86_64) TestBrowser/1.0',
            ],
            content: (string) json_encode($report, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed>|null */
    private function rowFor(string $message): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT line, occurrences, stack, url FROM client_error WHERE message = ?',
            [$message],
        );

        return false === $row ? null : $row;
    }

    private function rowsFor(string $message): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM client_error WHERE message = ?',
            [$message],
        );
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        // Without this the kernel is rebooted between requests and the new
        // container's connection cannot see the uncommitted work.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        return $client;
    }

    private function signedIn(): KernelBrowser
    {
        $client = $this->boot();

        $user            = new User();
        $user->email     = 'client-error-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Fixture';
        $user->nameLast  = 'Person';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        $client->loginUser($user);

        return $client;
    }

    /**
     * The `ajax` token, the way the meta tag carries it.
     *
     * The GET first is load-bearing — same trick, and the same reason,
     * AdminDataResetTest records.
     */
    private function token(KernelBrowser $client): string
    {
        $client->request('GET', '/mail/inbox');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken('ajax')
                ->getValue();
        } finally {
            $stack->pop();
        }
    }
}
