<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Stateless CSRF tokens are enforced by the same-origin check, and only by it.
 *
 * The `submit`, `authenticate`, `logout` and `compose` ids in csrf.yaml render
 * a placeholder, not a secret; what makes them a defence is that
 * SameOriginCsrfTokenManager refuses a request whose Sec-Fetch-Site, Origin or
 * Referer names another site. There used to be a double-submit script beside
 * this that was meant to add a cookie layer on top — it never loaded, and was
 * removed rather than switched on (csrf.yaml says why). This pins that the
 * layer that is actually in force holds: a cross-site POST carrying a token
 * lifted from the page is refused.
 */
final class StatelessCsrfOriginTest extends WebTestCase
{
    public function testACrossSiteLoginPostIsRefusedEvenWithTheRenderedToken(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');
        $token   = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/login', [
            'email'       => 'nobody@plmail.test',
            'password'    => 'irrelevant',
            '_csrf_token' => $token,
        ], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $client->followRedirect();

        self::assertStringContainsString(
            'Invalid CSRF token',
            (string) $client->getResponse()->getContent(),
            'a cross-site POST was not refused on its CSRF token',
        );
    }
}
