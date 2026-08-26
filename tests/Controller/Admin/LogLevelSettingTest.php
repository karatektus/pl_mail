<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Setting the capture level from the page that shows the logs.
 *
 * The level used to live only in `APP_DB_LOG_LEVEL`, which meant editing a file
 * on the host and restarting the stack — at the exact moment that is least
 * convenient, because the reason to change it is that something is wrong and
 * the answer is one level further down. That is how installs end up running on
 * `info` for months.
 *
 * Two things are asserted beyond "it saves". A rejected value must not become a
 * stored one, since this control writes what the logger obeys; and the empty
 * option has to clear the row rather than store the environment's current
 * value, or there would be no way back to following the configuration once
 * anything had been chosen.
 *
 * Skips itself without the seeded admin, as the admin tests next door do.
 */
final class LogLevelSettingTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($admin);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM log_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnAdminCanChooseTheLevelThatIsKept(): void
    {
        $this->post('info');

        self::assertResponseRedirects();
        self::assertSame('info', $this->storedLevel());
    }

    /**
     * The way back to the environment. Storing its current value instead would
     * look identical today and diverge silently the next time it changed.
     */
    public function testTheEmptyChoiceClearsTheStoredLevel(): void
    {
        $this->post('info');
        self::assertSame('info', $this->storedLevel());

        $this->post('');

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM log_settings'),
            'the row stays; only the choice is withdrawn',
        );
        self::assertNull($this->storedLevel());
    }

    /**
     * Anything outside the closed set is refused, not stored. This value is
     * what the logger obeys, so a typo reaching the column would be a mailbox
     * quietly recording nothing.
     */
    public function testAnUnknownLevelIsNotStored(): void
    {
        $this->post('chatty');

        self::assertNull($this->storedLevel());
    }

    /** One row, however many times it is set. */
    public function testSettingItRepeatedlyKeepsOneRow(): void
    {
        $this->post('info');
        $this->post('error');
        $this->post('warning');

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM log_settings'),
            'the singleton index is the only thing that can really hold this',
        );
        self::assertSame('warning', $this->storedLevel());
    }

    public function testItRefusesAPostWithoutAValidToken(): void
    {
        $this->client->request('POST', '/admin/logs/level', ['capture' => 'info', '_token' => 'nope']);

        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->storedLevel());
    }

    /**
     * Read from the table rather than through the ORM.
     *
     * KernelBrowser reboots the kernel between requests, so a repository held
     * from setUp() answers out of an identity map filled before the write —
     * which reported the old level and made this suite claim a bug that was not
     * there. The column is what the resolver reads anyway.
     */
    private function storedLevel(): ?string
    {
        $value = $this->connection->fetchOne('SELECT minimum_level FROM log_settings ORDER BY id ASC LIMIT 1');

        return true === is_string($value) ? $value : null;
    }

    private function post(string $capture): void
    {
        $this->client->request('POST', '/admin/logs/level', [
            'capture' => $capture,
            '_token'  => $this->token(),
        ]);
    }

    /**
     * Taken off the rendered form rather than minted from the container: the
     * token store is the session, and there is no session until a request has
     * been made. Reading it from the page is also the honest version — it is
     * the token a browser would send.
     */
    private function token(): string
    {
        $crawler = $this->client->request('GET', '/admin/logs');

        self::assertResponseIsSuccessful();

        return (string) $crawler
            ->filter('form[action$="/logs/level"] input[name="_token"]')
            ->attr('value');
    }
}
