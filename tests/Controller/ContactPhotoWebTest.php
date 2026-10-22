<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The web pages load contact photos on their own session. They used to point
 * at the JMAP route, whose stateless firewall answers a browser with a Basic
 * challenge — a "Sign in" box that no password could get past.
 */
final class ContactPhotoWebTest extends WebTestCase
{
    public function testAPhotoUrlOnTheWebNeverAsksForBasicCredentials(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'e2e-admin@plmail.test']);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->request('GET', '/contacts/999999999/photo');

        // Not this user's contact: a plain 404, and above all no Basic challenge.
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertFalse($client->getResponse()->headers->has('WWW-Authenticate'));
    }
}
