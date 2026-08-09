<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\User\UserRepository;
use App\Service\Backup\ConfigBackupExporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the first-run restore screen says once a backup can carry people.
 *
 * Two things are being asserted, and they are the two the operator sees:
 *
 * **The install is finished, and the page knows it.** A restore that brought
 * users with it has closed /install — InstallGuard answers on the user count —
 * so the page must stop saying "now create the administrator" and offer to sign
 * in as somebody the file restored, naming them. Getting this wrong is not
 * cosmetic: the old ending sends an operator to a route that now 404s, at the
 * end of the one flow where they have no other page to go to.
 *
 * **The list of chores is only chores.** An environment value restored to
 * exactly what this environment already has is not a task, and until this
 * change every stock restore opened with two of them — MAILER_DSN and
 * MESSENGER_TRANSPORT_DSN, which compose pins and a backup used to carry. This
 * container really does set both, so the fixtures here reproduce the original
 * complaint rather than simulating it.
 *
 * Everything runs inside a transaction that is rolled back, and the generated
 * secrets file — which is outside any transaction — is snapshotted and put
 * back, exactly as InstallRestoreTest does and for the same reason.
 */
final class InstallRestoreUsersTest extends WebTestCase
{
    private const string PASSWORD = 'a properly long backup password';

    private const string ADMIN_EMAIL = 'anna@restored.test';

    private const string SECOND_EMAIL = 'bo@restored.test';

    private const string PASSWORD_HASH = '$2y$13$0123456789012345678901uJ7yCgMbNoMYVUFsyDhV0EjJb9dRSkq';

    private ?Connection $connection = null;

    private ?string $secretsPath = null;

    private ?string $secretsBefore = null;

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;

        if (null !== $this->secretsPath) {
            null === $this->secretsBefore
                ? (is_file($this->secretsPath) ? unlink($this->secretsPath) : null)
                : file_put_contents($this->secretsPath, $this->secretsBefore);

            $this->secretsPath   = null;
            $this->secretsBefore = null;
        }

        parent::tearDown();
    }

    /**
     * The ending this whole task exists to produce: two accounts back, the
     * administrator named, and the button going to the sign-in page.
     */
    public function testARestoreThatCarriedUsersOffersSignInInsteadOfCreatingAnAdministrator(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        $review = $client->request('POST', '/install/restore', [
            '_token'   => $this->token($client, 'install_restore'),
            'password' => self::PASSWORD,
        ], [
            'backup' => $this->upload($this->sealedFixture()),
        ]);

        self::assertResponseIsSuccessful();

        // The review is still only a review: nobody exists yet, and the page
        // must not have offered to sign in as somebody it has not created.
        self::assertSame(0, static::getContainer()->get(UserRepository::class)->countAll());
        self::assertStringContainsString(self::ADMIN_EMAIL, (string) $client->getResponse()->getContent());

        $client->submit($review->filter('#restore-apply')->form(['password' => self::PASSWORD]));

        self::assertResponseIsSuccessful();

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $users = static::getContainer()->get(UserRepository::class);

        self::assertSame(2, $users->countAll(), 'the backup carried two people');

        $anna = $users->findOneBy(['email' => self::ADMIN_EMAIL]);

        self::assertInstanceOf(User::class, $anna);
        self::assertSame(self::PASSWORD_HASH, $anna->password, 'the restored password would not open the account');
        self::assertSame([User::ROLE_ADMIN], $anna->roles);

        $body = (string) $client->getResponse()->getContent();

        // The finish action goes to the login page, and says so.
        self::assertStringContainsString('href="/login"', $body);
        self::assertStringContainsString('Sign in', $body);

        // And no longer to a page that has just closed behind it.
        self::assertStringNotContainsString('Continue — create the administrator', $body);
        self::assertStringNotContainsString('still has no users', $body);

        // The count and the administrator by name, which is how an operator
        // checks that the right file went in.
        self::assertStringContainsString('2 restored', $body);
        self::assertStringContainsString(self::ADMIN_EMAIL, $body);

        // The install really is over: both setup doors answer 404 from here on.
        foreach (['/install', '/install/restore'] as $path) {
            $client->request('GET', $path);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $path . ' is still open after a restore');
        }
    }

    /**
     * A backup carrying no users leaves the old ending exactly as it was.
     *
     * The regression guard for every file an operator is already holding: the
     * page must not offer to sign in as nobody.
     */
    public function testABackupWithoutUsersStillLeadsToCreatingTheAdministrator(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        $review = $client->request('POST', '/install/restore', [
            '_token'   => $this->token($client, 'install_restore'),
            'password' => self::PASSWORD,
        ], [
            'backup' => $this->upload($this->sealedFixture(users: [])),
        ]);

        $client->submit($review->filter('#restore-apply')->form(['password' => self::PASSWORD]));

        self::assertResponseIsSuccessful();
        self::assertSame(0, static::getContainer()->get(UserRepository::class)->countAll());

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Continue — create the administrator', $body);
        self::assertStringNotContainsString('href="/login"', $body);

        $client->request('GET', '/install');
        self::assertResponseIsSuccessful();
    }

    /**
     * The two noisy rows are gone, counted rather than listed, and the
     * encryption-key block is a closed disclosure.
     *
     * The fixture is an OLD backup — one that still carries MAILER_DSN and
     * MESSENGER_TRANSPORT_DSN — because those files exist and this is the
     * behaviour they get. A backup written by this build carries neither.
     */
    public function testValuesAlreadyMatchingThisEnvironmentAreCountedRatherThanListedAsChores(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        $client->request('POST', '/install/restore', [
            '_token'   => $this->token($client, 'install_restore'),
            'password' => self::PASSWORD,
        ], [
            // The values this container actually runs with, so they are
            // genuinely identical and genuinely not tasks.
            'backup' => $this->upload($this->sealedFixture(env: [
                'MAILER_DSN'              => 'null://null',
                'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
                'APP_ENCRYPTION_KEY'      => 'dGhlIG9sZCBtYWNoaW5lcyBrZXksIHRoaXJ0eS10d28=',
            ])),
        ]);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        // Not printed as chores, in the imperative, under "yours to do".
        self::assertStringNotContainsString('MAILER_DSN=null://null', $body);
        self::assertStringNotContainsString('MESSENGER_TRANSPORT_DSN=in-memory://', $body);
        self::assertStringNotContainsString('These are yours to do', $body);

        // Counted once, quietly.
        self::assertStringContainsString('2 more already match this environment', $body);

        // The key note is reachable and shut: a <details> whose summary is
        // addressed to the one operator who needs it.
        self::assertStringContainsString('<details', $body);
        self::assertStringContainsString('Also restoring the old database?', $body);
        // Still carrying the line that operator came for, inside the disclosure.
        self::assertStringContainsString('APP_ENCRYPTION_KEY=dGhlIG9sZCBtYWNoaW5lcyBrZXksIHRoaXJ0eS10d28=', $body);
    }

    /**
     * With nothing left to do, the page says so and puts the way out first.
     *
     * "First" is asserted by position rather than by presence, because the
     * complaint was never that the button was missing — it was that an operator
     * had to read a list of non-tasks to discover there was nothing in it.
     */
    public function testWhenNothingIsActionableThePageSaysSoAndLeadsWithTheFinishAction(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        $review = $client->request('POST', '/install/restore', [
            '_token'   => $this->token($client, 'install_restore'),
            'password' => self::PASSWORD,
        ], [
            'backup' => $this->upload($this->sealedFixture(env: ['MAILER_DSN' => 'null://null'])),
        ]);

        $client->submit($review->filter('#restore-apply')->form(['password' => self::PASSWORD]));

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('These are yours to do', $body);
        self::assertStringContainsString('There is nothing left for you to do here.', $body);

        $finish = strpos($body, 'id="restore-finish"');
        $plan   = strpos($body, 'id="restore-plan"');

        self::assertIsInt($finish, 'the finish action is not on the page at all');
        self::assertIsInt($plan);
        self::assertLessThan($plan, $finish, 'the receipt is above the way out');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function bootOnAnEmptyInstall(): KernelBrowser
    {
        $client = static::createClient();
        // Without this the kernel is rebooted between requests and the new
        // container's connection cannot see the uncommitted truncate.
        $client->disableReboot();

        $entityManager    = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $entityManager->getConnection();

        $this->connection->beginTransaction();

        $tables = array_filter(
            $this->connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', implode(', ', $tables)));
        $entityManager->clear();

        $this->secretsPath   = static::getContainer()->get(GeneratedSecretsFile::class)->path();
        $this->secretsBefore = is_file($this->secretsPath) ? (file_get_contents($this->secretsPath) ?: null) : null;

        return $client;
    }

    /**
     * A backup with no `files` section on purpose — a write into var/secrets
     * would escape the transaction this rolls back, and what a file write does
     * is ConfigBackupImporterTest's subject.
     *
     * @param array<string, string>|null      $env
     * @param array<string, mixed>|null       $users
     */
    private function sealedFixture(?array $env = null, ?array $users = null): string
    {
        return static::getContainer()->get(ConfigBackupCipher::class)->seal([
            'format'     => ConfigBackupCipher::FORMAT,
            'version'    => ConfigBackupExporter::DOCUMENT_VERSION,
            'exportedAt' => '2026-01-01T00:00:00+00:00',
            'instance'   => 'https://the-old-machine.plmail.test',
            'env'        => $env ?? [],
            'files'      => [],
            'database'   => [],
            'users'      => $users ?? [
                self::ADMIN_EMAIL  => $this->person('Anna', [User::ROLE_ADMIN]),
                self::SECOND_EMAIL => $this->person('Bo', []),
            ],
        ], self::PASSWORD);
    }

    /**
     * The smallest user a document can carry: no mailboxes, no labels, nothing
     * under them. What is being asserted on this page is the *page*, and the
     * subtree is ConfigBackupUsersTest's subject.
     *
     * @param list<string> $roles
     *
     * @return array<string, mixed>
     */
    private function person(string $firstName, array $roles): array
    {
        return [
            'nameFirst' => $firstName,
            'nameLast'  => 'Beispiel',
            'roles'     => $roles,
            'password'  => self::PASSWORD_HASH,
            'locale'    => 'en',
            'twoFactor' => ['secret' => null, 'confirmedAt' => null, 'recoveryCodes' => []],
        ];
    }

    private function upload(string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'plmail-backup-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'plmail-config-2026-01-01.backup', 'application/octet-stream', null, true);
    }

    /**
     * A real CSRF token for $id, minted against the session the page it belongs
     * to already established — the carrier-request trick InstallRestoreTest
     * records, and for the same reason.
     */
    private function token(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/install/restore');

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
