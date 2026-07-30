<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /install creates an administrator without asking anyone for credentials.
 *
 * That is safe on an empty install and nowhere else, so what is worth pinning
 * is the "nowhere else" half: with a user in the database the page must be gone
 * and a POST to it must write nothing. The happy path is covered end to end by
 * tests/e2e/install.spec.ts, which can start from a genuinely empty database;
 * this suite runs against the seeded one and so is the closed case by
 * construction.
 *
 * Skips itself without the seeded user rather than failing, matching
 * PageRendersTest — a database with no users is the one state in which /install
 * is *supposed* to answer.
 */
final class InstallTest extends WebTestCase
{
    public function testItIsGoneOnceAUserExists(): void
    {
        $client = static::createClient();
        $this->requireExistingUsers();

        $client->request('GET', '/install');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testItCreatesNothingWhenPostedToOnceAUserExists(): void
    {
        $client = static::createClient();
        $this->requireExistingUsers();

        $users  = static::getContainer()->get(UserRepository::class);
        $before = $users->countAll();

        $client->request('POST', '/install', [
            'first_admin' => [
                'nameFirst'     => 'Mallory',
                'nameLast'      => 'Uninvited',
                'email'         => 'mallory@plmail.test',
                'plainPassword' => ['first' => 'correct-horse-battery', 'second' => 'correct-horse-battery'],
            ],
        ]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame($before, $users->countAll(), 'a closed /install must not create a user');
        self::assertNull($users->findOneBy(['email' => 'mallory@plmail.test']));
    }

    /**
     * The login page redirects to /install only while the install is empty.
     * Getting this backwards would send every signed-out visitor to a page that
     * mints administrators.
     */
    public function testTheLoginPageDoesNotPointAtItOnceAUserExists(): void
    {
        $client = static::createClient();
        $this->requireExistingUsers();

        $client->request('GET', '/login');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    private function requireExistingUsers(): void
    {
        if (0 === static::getContainer()->get(UserRepository::class)->countAll()) {
            self::markTestSkipped('run `app:test:seed-user` first — with no users, /install is meant to answer');
        }
    }
}
