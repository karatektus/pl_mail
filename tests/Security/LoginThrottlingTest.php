<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The login form has a limit, and the limit is reached.
 *
 * Worth a test rather than trusting the config, because an inactive limiter is
 * indistinguishable from a working one until someone is actually attacked:
 * every request still renders the ordinary "bad credentials" page, and nothing
 * anywhere says the throttle never engaged.
 *
 * Before this, `login_throttling` was simply absent — a password form on an
 * application whose README explains how to expose it to the internet.
 *
 * Asserted on the rendered message rather than on a 429. Symfony's throttling
 * listener raises an authentication exception, which the firewall handles like
 * any other failed login: a redirect back to the form. There is no status code
 * that distinguishes it, which is exactly why the naive version of this test
 * passed against no limiter at all.
 */
final class LoginThrottlingTest extends WebTestCase
{
    private const string PROBE = 'throttle-probe@example.test';

    /**
     * The limiter's counters live in a filesystem cache pool with a 15-minute
     * window, so they outlive the process. Left alone they accumulate across
     * runs until the per-IP backstop is exhausted and every test in this file
     * "passes" for the wrong reason — and the negative case below fails.
     */
    protected function setUp(): void
    {
        static::bootKernel();

        $pool = static::getContainer()->get('cache.rate_limiter');

        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }

        static::ensureKernelShutdown();
    }

    public function testRepeatedBadPasswordsAreEventuallyRefused(): void
    {
        $client = static::createClient();
        $client->followRedirects(true);

        // One past max_attempts (5), with headroom. Which exact attempt trips
        // it is an implementation detail; that it trips at all is not.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $client->request('POST', '/login', [
                'email'    => self::PROBE,
                'password' => 'definitely-the-wrong-password',
            ]);
        }

        self::assertStringContainsString(
            'Too many failed login attempts',
            (string) $client->getResponse()->getContent(),
            'the login form accepted 8 consecutive bad passwords without throttling',
        );
    }

    /**
     * The limit is per username, not per IP.
     *
     * An IP key would let one attacker — or one housemate behind the same NAT
     * address — lock every other account on the install out of its own login.
     */
    public function testAnotherAccountIsUnaffected(): void
    {
        $client = static::createClient();
        $client->followRedirects(true);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $client->request('POST', '/login', [
                'email'    => self::PROBE,
                'password' => 'definitely-the-wrong-password',
            ]);
        }

        $client->request('POST', '/login', [
            'email'    => 'other-probe@example.test',
            'password' => 'also-wrong',
        ]);

        self::assertStringNotContainsString(
            'Too many failed login attempts',
            (string) $client->getResponse()->getContent(),
            'throttling one account locked out a different one',
        );
    }
}
