<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Event\Subscriber;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A signed-in reader's responses say how long they took and how much of it was
 * the database; nobody else's do. See ServerTimingSubscriber.
 */
final class ServerTimingSubscriberTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    public function testASignedInReadersListSaysWhereItsTimeWent(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (!$user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->request('GET', '/mail/inbox', server: ['HTTP_TURBO_FRAME' => 'inbox-list-frame']);

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression(
            '/^db;dur=\d+\.\d;desc="(\d+) quer(y|ies)", app;dur=\d+\.\d$/',
            (string) $client->getResponse()->headers->get('Server-Timing'),
        );

        preg_match('/desc="(\d+) /', (string) $client->getResponse()->headers->get('Server-Timing'), $count);
        self::assertGreaterThan(0, (int) $count[1], 'a list cannot be drawn without reading anything');

        // The next request counts its own statements, not these as well.
        $client->request('GET', '/mail/inbox', server: ['HTTP_TURBO_FRAME' => 'inbox-list-frame']);
        preg_match('/desc="(\d+) /', (string) $client->getResponse()->headers->get('Server-Timing'), $again);
        self::assertLessThanOrEqual((int) $count[1] + 2, (int) $again[1]);
    }

    public function testNobodySignedOutIsTold(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertFalse($client->getResponse()->headers->has('Server-Timing'));
    }
}
