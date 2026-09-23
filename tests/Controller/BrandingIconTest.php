<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Appearance\LogoIcons;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The icon tiles: public pictures, cached for a year at the drawing's version.
 *
 * Two claims, and the second is the one that breaks quietly. An icon is served
 * for every motif × paint the enums know and for nothing else. And it stays
 * PUBLIC for a signed-in browser — the route sits outside the session firewall
 * because a response on a request that read the session is rewritten by
 * Symfony to `private, max-age=0`, which would not fail a single functional
 * test and would re-fetch forty-two tiles on every visit to the appearance
 * pane.
 */
final class BrandingIconTest extends WebTestCase
{
    public function testAnIconIsAPublicSvgThatStaysPublicForASignedInBrowser(): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'e2e-admin@plmail.test']);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        $version = static::getContainer()->get(LogoIcons::class)->version();

        $client->request('GET', '/branding/icon/blue-horn/ocean.svg', ['v' => $version]);

        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('#0369a1', (string) $client->getResponse()->getContent(), 'the ocean ramp paints the horn');

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('immutable', $cache, 'the versioned URL is the one a year is promised to');
        self::assertStringNotContainsString('private', $cache);
    }

    public function testAnUnknownMotifOrPaintIsNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/icon/kraken/ocean.svg');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/branding/icon/blue-horn/chartreuse-dreams.svg');
        self::assertResponseStatusCodeSame(404);
    }
}
