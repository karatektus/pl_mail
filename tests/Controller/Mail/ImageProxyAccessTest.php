<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Service\Mail\ImageProxySigner;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The image proxy answers a browser that holds no session.
 *
 * WHY THIS IS THE WHOLE POINT
 * ---------------------------
 * The mail body renders in a sandbox with no `allow-same-origin` — an opaque
 * origin, so a sanitizer gap can never reach the app origin or its cookies. The
 * price is that the frame sends NO session cookie with its `<img>` loads, so
 * every proxied image arrives unauthenticated. While the route sat behind
 * ROLE_USER those requests were answered with the login page (200, text/html),
 * which an `<img>` renders as a broken icon — remote images were broken for
 * everyone. The route is authorized by its HMAC signature instead: a global
 * capability token minted with the app secret, no user in it, so anonymous
 * access is correct and per-session identity would add nothing.
 *
 * These cases never touch the network: the signed URL is a loopback literal the
 * fetcher refuses before it opens a socket, so the response is the placeholder
 * either way. What is under test is the FIREWALL, not the fetch — an anonymous
 * caller must get image bytes, never a redirect to the login form.
 */
final class ImageProxyAccessTest extends WebTestCase
{
    /**
     * A refused target, so no egress: the range check rejects loopback before
     * any connection. The response is therefore always the placeholder GIF, and
     * the assertions are about who was allowed to ask.
     */
    private const string REFUSED_URL = 'https://127.0.0.1/pixel.png';

    public function testAnAnonymousRequestWithAValidSignatureIsAnsweredNotRedirected(): void
    {
        $client = static::createClient();

        $signature = static::getContainer()->get(ImageProxySigner::class)->sign(self::REFUSED_URL);

        // No loginUser(): this is the sandbox frame's cookieless request.
        $client->request('GET', '/mail/image-proxy', ['u' => self::REFUSED_URL, 's' => $signature]);

        $response = $client->getResponse();

        // The regression: behind the firewall this was a 302 to /login, or the
        // login HTML. Anonymous access means an image answer, whatever the fetch
        // decided.
        self::assertResponseIsSuccessful();
        self::assertFalse($response->isRedirection(), 'the proxy must not bounce the frame to a login page');
        self::assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        self::assertStringNotContainsStringIgnoringCase('<title', (string) $response->getContent());
    }

    public function testAMissingSignatureIsRefusedWithAPlaceholderNotLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/mail/image-proxy', ['u' => self::REFUSED_URL]);

        $response = $client->getResponse();

        // Still public, still an image — the refusal is the placeholder, not an
        // auth challenge, so a caller learns nothing from the difference.
        self::assertResponseIsSuccessful();
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
    }

    public function testAForgedSignatureIsRefusedWithAPlaceholder(): void
    {
        $client = static::createClient();

        $client->request('GET', '/mail/image-proxy', ['u' => self::REFUSED_URL, 's' => 'deadbeefdeadbeefdeadbeefdeadbeef']);

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
    }
}
