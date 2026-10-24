<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Entity\Mail\Account;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pressing Compose before any mailbox is set up.
 *
 * The server has a card for that — "No mail account yet", with the way to add
 * one — and for a while nobody ever saw it: it came back without the frame the
 * click asked for, so Turbo raised turbo:frame-missing and compose--dock showed
 * "The compose window could not be opened. Please try again." instead. Once it
 * did arrive, its button tried to load Settings into the compose slot and
 * failed the same way. Both are about the markup, so both are pinned here.
 */
final class ComposeWithoutAccountTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCardArrivesInTheFrameTheClickAskedFor(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        $user      = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();

        // Switched off counts as none: ComposeWindow::defaultAccountFor()
        // offers only active accounts.
        $container->get('doctrine.orm.entity_manager')
            ->createQuery('UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr')
            ->setParameter('usr', $user)
            ->execute();

        $crawler = $client->request('GET', '/compose/new', server: ['HTTP_TURBO_FRAME' => 'compose_dock']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#compose_dock'), 'or Turbo reports the frame missing');
        self::assertCount(1, $crawler->filter('turbo-frame#compose_dock #compose-no-account-heading'));
        self::assertSame(
            '_top',
            $crawler->filter('a[href="/settings?section=accounts"]')->attr('data-turbo-frame'),
            'the way out leaves the compose slot',
        );
        self::assertCount(1, $crawler->filter('button[data-action="compose--dock#dismiss"]'));
    }
}
