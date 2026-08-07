<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\Push\FcmConfigRepository;
use App\Repository\User\UserRepository;
use App\Service\Backup\ConfigBackupDatabase;
use App\Service\Backup\ConfigBackupExporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Restoring a config backup as the first thing a new install does.
 *
 * The second door onto /install, and it inherits that page's whole security
 * story: it creates nothing and authenticates nobody, and it is safe only for
 * as long as the install has no users. So the guard is asserted first and in
 * both directions — a restore endpoint that stayed open after setup would be an
 * unauthenticated way to rewrite an installation's Firebase and OAuth
 * credentials, which is a considerably worse door than the one it was modelled
 * on.
 *
 * The interesting half is the encryption key. A fresh install has minted its
 * own at first container start, so a backup restored here is by definition
 * being opened by a different key from the one that wrote it — which is the
 * exact case the decrypted-inside-the-envelope design exists for and the exact
 * case nothing else exercises end to end. The swap is done through
 * EncryptedStringType's static seam, the one Kernel::boot() uses, because that
 * is as close to "a different machine" as one process gets.
 *
 * The other half is the one this feature was reworked for: on a fresh install,
 * uploading the file and typing its password is supposed to be the whole job.
 * That claim is only true if the environment values are genuinely written into
 * the generated secrets file the entrypoint loads at the next start, so this
 * asserts the file and not only the page.
 *
 * Everything is done inside a transaction that is rolled back — without it this
 * test destroys the seed every other suite and the e2e run depend on, which is
 * the note InstallEmptyInstallTest makes for the same reason. The generated
 * secrets file is outside any transaction, so it is snapshotted and put back
 * instead.
 */
final class InstallRestoreTest extends WebTestCase
{
    private const string PASSWORD = 'a properly long backup password';

    private ?Connection $connection = null;

    private ?Encryptor $originalEncryptor = null;

    private ?string $secretsPath = null;

    private ?string $secretsBefore = null;

    protected function tearDown(): void
    {
        if (null !== $this->originalEncryptor) {
            EncryptedStringType::setEncryptor($this->originalEncryptor);
            $this->originalEncryptor = null;
        }

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
     * The seeded database has users, so this is the ordinary state of every
     * install that has ever been set up.
     */
    public function testAnInstallThatHasUsersDoesNotExposeTheRestoreAtAll(): void
    {
        $client = static::createClient();

        self::assertGreaterThan(
            0,
            static::getContainer()->get(UserRepository::class)->countAll(),
            'the fixture is only valid on an install that has been set up',
        );

        foreach ([['GET', '/install/restore'], ['POST', '/install/restore'], ['POST', '/install/restore/apply']] as [$method, $path]) {
            $client->request($method, $path);

            // 404 rather than a redirect, for the reason InstallGuard states: a
            // redirect confirms the endpoint exists and is merely closed.
            self::assertSame(404, $client->getResponse()->getStatusCode(), $method . ' ' . $path . ' is still open');
        }
    }

    public function testTheFirstScreenOffersTheRestoreBesideTheOrdinaryPath(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        $client->request('GET', '/install');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/install/restore', (string) $client->getResponse()->getContent());
    }

    /**
     * The whole path, end to end, with the key changed underneath it: upload,
     * review, apply, and then the account creation that the restore did not do.
     */
    public function testARestoredInstallKeepsItsFirebaseCredentialsUnderItsOwnNewKey(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        // The backup was sealed by another install. This one has its own key,
        // and it is not that one.
        $envelope = $this->sealedFixture();
        $this->becomeADifferentInstallation();

        $review = $client->request('POST', '/install/restore', [
            '_token'   => $this->token($client, 'install_restore'),
            'password' => self::PASSWORD,
        ], [
            'backup' => $this->upload($envelope),
        ]);

        self::assertResponseIsSuccessful();
        // Nothing has happened yet — the review is a review here too.
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM fcm_config'));

        $reviewBody = (string) $client->getResponse()->getContent();

        // The onboarding screen is not an instruction wall any more:
        // GOOGLE_OAUTH_CLIENT_ID is listed as something plMail writes, and its
        // value is NOT handed back as a line to paste anywhere.
        self::assertStringContainsString('GOOGLE_OAUTH_CLIENT_ID', $reviewBody);
        self::assertStringNotContainsString('GOOGLE_OAUTH_CLIENT_ID=an-id.apps.googleusercontent.com', $reviewBody);

        // APP_SECRET is the residue and is honest about being one: this test
        // container really does carry it in its process environment, the way a
        // compose file would, so the entrypoint would override the restored
        // value at the next start and the page says so with the line to fix.
        self::assertStringContainsString('APP_SECRET=a-restored-secret', $reviewBody);

        $client->submit($review->filter('#restore-apply')->form(['password' => self::PASSWORD]));

        self::assertResponseIsSuccessful();

        // Written where the next container start reads it — this is what makes
        // the instance come up as the one the backup was made from.
        self::assertSame(
            'an-id.apps.googleusercontent.com',
            static::getContainer()->get(GeneratedSecretsFile::class)->read()['GOOGLE_OAUTH_CLIENT_ID'] ?? null,
        );

        // One restart notice for the whole plan, and the account form still
        // the next action.
        self::assertStringContainsString('restart the stack', (string) $client->getResponse()->getContent());

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $restored = static::getContainer()->get(FcmConfigRepository::class)->current();

        self::assertNotNull($restored, 'the restore wrote nothing');
        self::assertSame('plmail-onboarding-test', $restored->projectId);
        self::assertStringContainsString('private_key', (string) $restored->serviceAccountJson);

        // The account is still to be created — a config backup carries
        // configuration, not people — and the page has to say so and lead there.
        self::assertSame(0, static::getContainer()->get(UserRepository::class)->countAll());
        self::assertStringContainsString('/install', (string) $client->getResponse()->getContent());

        // And the ordinary path still works afterwards, which is what "the
        // restore did not half-configure the install" means in practice.
        $client->request('GET', '/install');
        self::assertResponseIsSuccessful();
    }

    /**
     * A wrong password at setup must not consume the attempt: nothing written,
     * nothing marked done, and the two setup pages still there.
     */
    public function testAWrongPasswordLeavesTheSetupExactlyWhereItWas(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        $client->request('POST', '/install/restore', [
            '_token'   => $this->token($client, 'install_restore'),
            'password' => 'not the password at all',
        ], [
            'backup' => $this->upload($this->sealedFixture()),
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('does not open this file', (string) $client->getResponse()->getContent());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM fcm_config'));
        self::assertSame(0, static::getContainer()->get(UserRepository::class)->countAll());

        $client->request('GET', '/install');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/install/restore');
        self::assertResponseIsSuccessful();
    }

    /**
     * A 302 rather than a 403, and that is not the assertion being loose. This
     * page has no session to be forbidden from, so the firewall's entry point
     * turns the refusal into a redirect at the login page — which on a userless
     * install redirects here again. What matters is that the request did not go
     * through, and that is what is asserted twice: the response and the table.
     */
    public function testAPostWithoutACsrfTokenRestoresNothing(): void
    {
        $client = $this->bootOnAnEmptyInstall();

        $client->request('POST', '/install/restore', ['password' => self::PASSWORD], ['backup' => $this->upload($this->sealedFixture())]);

        self::assertResponseRedirects();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM fcm_config'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function bootOnAnEmptyInstall(): KernelBrowser
    {
        $client = static::createClient();
        // Without this the kernel is rebooted between requests, and the new
        // container's connection cannot see the uncommitted truncate.
        $client->disableReboot();

        $entityManager           = static::getContainer()->get(EntityManagerInterface::class);
        $this->originalEncryptor = static::getContainer()->get(Encryptor::class);
        $this->connection        = $entityManager->getConnection();

        $this->connection->beginTransaction();

        $tables = array_filter(
            $this->connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', implode(', ', $tables)));
        $entityManager->clear();

        // Outside the transaction, so remembered and put back by hand.
        $this->secretsPath   = static::getContainer()->get(GeneratedSecretsFile::class)->path();
        $this->secretsBefore = is_file($this->secretsPath) ? (file_get_contents($this->secretsPath) ?: null) : null;

        return $client;
    }

    /**
     * What the target instance's key would be: its own, minted at first start
     * and unrelated to whatever sealed the file.
     */
    private function becomeADifferentInstallation(): void
    {
        EncryptedStringType::setEncryptor(new Encryptor('YW5vdGhlciBpbnN0YWxsYXRpb25zIGtleSwgMzIgYnk='));
    }

    /**
     * A backup with no `files` section on purpose: a write into var/secrets
     * would escape the transaction this test rolls back, and what a file write
     * does is ConfigBackupImporterTest's subject.
     */
    private function sealedFixture(): string
    {
        return static::getContainer()->get(ConfigBackupCipher::class)->seal([
            'format'     => ConfigBackupCipher::FORMAT,
            'version'    => ConfigBackupExporter::DOCUMENT_VERSION,
            'exportedAt' => '2026-01-01T00:00:00+00:00',
            'instance'   => 'https://the-old-machine.plmail.test',
            'env'        => [
                'APP_SECRET'             => 'a-restored-secret',
                'APP_ENCRYPTION_KEY'     => 'dGhlIG9sZCBtYWNoaW5lcyBrZXksIHRoaXJ0eS10d28=',
                'GOOGLE_OAUTH_CLIENT_ID' => 'an-id.apps.googleusercontent.com',
            ],
            'files'      => [],
            'database'   => [
                ConfigBackupDatabase::FCM_CONFIG => [
                    'isEnabled'          => true,
                    'serviceAccountJson' => '{"type":"service_account","project_id":"plmail-onboarding-test",'
                        . '"client_email":"p@plmail-onboarding-test.iam.gserviceaccount.com",'
                        . '"private_key":"-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n"}',
                    'projectId'      => 'plmail-onboarding-test',
                    'clientEmail'    => 'p@plmail-onboarding-test.iam.gserviceaccount.com',
                    'applicationId'  => '1:1:android:abc',
                    'apiKey'         => 'AIza-key',
                    'senderId'       => '1',
                    'androidPackage' => 'test.plmail',
                ],
            ],
        ], self::PASSWORD);
    }

    private function upload(string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'plmail-backup-');
        file_put_contents($path, $contents);

        return new UploadedFile(
            $path,
            'plmail-config-2026-01-01.backup',
            'application/octet-stream',
            null,
            true,
        );
    }

    /**
     * A real CSRF token for $id, minted against the session the page it belongs
     * to already established — the same carrier-request trick AdminDataResetTest
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
