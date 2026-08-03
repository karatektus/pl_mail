<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Service\Maintenance\ResetStage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The two doors on the reset panel.
 *
 * Exposing `app:reset` in a browser means a link and a stray form post can now
 * reach an operation that used to need a shell on the host. The first test is
 * the ordinary guard — not an admin, not admitted. The second is the one that
 * only the unrecoverable rungs have: a valid session and a valid CSRF token are
 * still not enough to erase the install, because the instance name has to have
 * been typed out. Both assert that nothing was destroyed as well as that the
 * request was refused, since a rejection issued after the truncation would look
 * identical from the outside.
 *
 * CSRF itself is covered project-wide by StateChangingPostsNeedCsrfTest and is
 * not repeated here.
 *
 * The mismatch test submits a REAL token, so it is a request that would have
 * succeeded on any of the top four rungs. It never submits a matching
 * confirmation: the transaction below would roll the truncation back, but the
 * full reset also empties var/attachments and rewrites the generated secrets
 * file, and neither of those is inside any transaction.
 */
final class AdminDataResetTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnOrdinaryUserCannotReachOrRunTheReset(): void
    {
        $client = $this->boot();
        $client->loginUser($this->seedUser());

        $before = $this->census();

        $client->request('GET', '/admin/reset/panel');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/reset/run/' . ResetStage::SyncedMail->value, [
            '_token'       => 'irrelevant',
            'confirmation' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->census(), 'a non-admin deleted something');
    }

    public function testAMistypedConfirmationDestroysNothing(): void
    {
        $client = $this->signInAsAdmin();
        $stage  = ResetStage::FullWithSecrets;

        $before = $this->census();

        $client->request('POST', '/admin/reset/run/' . $stage->value, [
            '_token'       => $this->token($client, $stage->csrfTokenId()),
            // Not the instance name, and not something a hand already moving
            // towards the button would produce either.
            'confirmation' => 'yes',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            'error=confirmation-mismatch',
            (string) $client->getResponse()->headers->get('Location'),
            'the operator was not told why nothing happened',
        );
        self::assertSame($before, $this->census(), 'the install was erased on a mistyped confirmation');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The counts that must never move when a request is refused: the operator
     * and the accounts holding the stored mailbox passwords.
     *
     * @return array{users: int, accounts: int}
     */
    private function census(): array
    {
        return [
            'users'    => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'),
            'accounts' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM account'),
        ];
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        // Without this the kernel is rebooted between requests and the new
        // container's connection cannot see the uncommitted work.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        return $client;
    }

    private function signInAsAdmin(): KernelBrowser
    {
        $client = $this->boot();

        $admin = $this->seedUser();
        $admin->addRole(User::ROLE_ADMIN);
        $this->em->flush();

        $client->loginUser($admin);

        return $client;
    }

    private function seedUser(): User
    {
        $user = new User();
        $user->email = 'reset-panel-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Fixture';
        $user->nameLast = 'Person';
        $user->roles = ['ROLE_USER'];
        $user->password = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A real CSRF token for $id.
     *
     * The GET first is load-bearing: token storage reads the session off the
     * request stack, and by the time a request has finished the stack is empty
     * again — so the session is pushed back on a carrier request to mint
     * against. Same trick as AdminUserManagementTest.
     */
    private function token(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/admin/reset/panel');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken($id)
                ->getValue();
        } finally {
            $stack->pop();
        }
    }
}
