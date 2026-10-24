<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261014110000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That every Outlook folder is handed back to the import once, so it is
 * followed again by a delta that can report a read.
 *
 * The links on an account were made with a $select that leaves out isRead, and
 * that select belongs to the link for good: changing what new chains ask for
 * does nothing for the ones already running. Exchange sends them no entry for
 * a message that was only read or unread, so the only way to hear about those
 * is a chain begun again.
 *
 * Run against rows, not against the SQL text: the failure to guard against is
 * a statement that is valid, applies, and touches the wrong accounts.
 */
final class GraphDeltaLinksResetMigrationTest extends KernelTestCase
{
    private const array LINKS = [
        'AAMkAD-inbox' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=old-inbox',
        'AAMkAD-sent'  => 'https://graph.microsoft.com/v1.0/me/mailFolders/y/messages/delta?$deltatoken=old-sent',
    ];

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAMicrosoftAccountFollowsNoFolderAfterwards(): void
    {
        $outlook = $this->seedAccount('microsoft', self::LINKS);

        self::assertSame(self::LINKS, $this->linksOf($outlook), 'the fixture starts out following both folders');

        $this->migrate();

        self::assertSame([], $this->linksOf($outlook), 'with no link a folder is the import\'s, and is listed again from the top');
    }

    /**
     * The companion to the test above, and the half a careless statement
     * breaks: `UPDATE account SET graph_delta_links = '{}'` with no WHERE is
     * just as good at emptying the first account, and also empties these.
     */
    public function testAccountsOfOtherProvidersAreLeftAlone(): void
    {
        $google = $this->seedAccount('google', self::LINKS);

        $this->migrate();

        self::assertSame(self::LINKS, $this->linksOf($google), 'a Gmail account does not use delta links, and the migration is not its business');
    }

    /**
     * Idempotent in effect: a mailbox that is already being imported, or has no
     * links to lose, comes out the same.
     */
    public function testAnAccountWithNoLinksStaysWithNone(): void
    {
        $outlook = $this->seedAccount('microsoft', []);

        $this->migrate();

        self::assertSame([], $this->linksOf($outlook));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Applies the migration's own statements, in this test's transaction, so the
     * database it started with is the database it leaves.
     */
    private function migrate(): void
    {
        // The migrations directory is read by Doctrine's own loader, not by
        // Composer, so nothing has autoloaded the class by the time a test runs.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261014110000.php';

        $migration = new Version20261014110000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $this->em->clear();
    }

    /**
     * @return array<string, string>
     */
    private function linksOf(Account $account): array
    {
        $json = $this->connection->fetchOne('SELECT graph_delta_links FROM account WHERE id = :id', ['id' => $account->id]);

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $links
     */
    private function seedAccount(string $provider, array $links): Account
    {
        $account                   = new Account();
        $account->usr              = $this->user;
        $account->name             = $provider . ' fixture';
        $account->email            = $provider . '-' . uniqid('', true) . '@example.test';
        $account->username         = uniqid($provider . '-', true);
        $account->imapHost         = 'imap.example.test';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = $provider;
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new \DateTimeImmutable('+1 day');
        $account->isActive         = true;
        $account->graphDeltaLinks  = $links;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'delta-reset-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Delta';
        $user->nameLast  = 'Reset';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
