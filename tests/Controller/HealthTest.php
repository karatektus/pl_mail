<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The probe Docker and uptime monitors call.
 *
 * Two properties, and both are easy to lose by accident: it must answer
 * without a session, and it must not become a place to read the instance's
 * internals from. An access_control rule added above it would close the first;
 * a well-meant "add the counts, they're useful" would open the second.
 */
final class HealthTest extends WebTestCase
{
    public function testItAnswersWithoutASession(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testAHealthyInstanceReportsOk(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');

        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('ok', $body['status']);
        self::assertTrue($body['checks']['database']);
    }

    /**
     * A worker that has never run is not a failure. The test stack runs no
     * workers at all, so this is also the shape a fresh install reports.
     */
    public function testAbsentWorkersAreUnknownRatherThanUnhealthy(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');

        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('workers', $body['checks']);
        self::assertNotFalse($body['checks']['workers']);
    }

    /**
     * The endpoint is unauthenticated, so what it says is what anyone who can
     * reach the port can read. It reports whether things work, never what they
     * contain — the numbers live behind ROLE_ADMIN at /admin.
     */
    public function testItLeaksNothingAboutTheInstance(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');

        $raw  = (string) $client->getResponse()->getContent();
        $body = json_decode($raw, true);

        self::assertSame(['status', 'checks'], array_keys($body));
        self::assertSame(['database', 'queue', 'workers'], array_keys($body['checks']));

        foreach ($body['checks'] as $value) {
            self::assertTrue(
                null === $value || is_bool($value),
                'a check reported something other than a verdict',
            );
        }

        foreach (['postgres', 'password', 'DATABASE_URL', '@', 'plmail_'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
    }

    /** HEAD and GET only; a probe must never be able to change anything. */
    public function testItRejectsNonGetMethods(): void
    {
        $client = static::createClient();

        $client->request('POST', '/healthz');

        self::assertResponseStatusCodeSame(405);
    }
}
