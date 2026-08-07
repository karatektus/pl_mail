<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Integration\MailProviderConfig;
use App\Entity\Push\FcmConfig;
use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Service\Backup\ConfigBackupDatabase;
use App\Service\Backup\ConfigBackupEnvironment;
use App\Service\Backup\ConfigBackupExporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The export carries the whole inventory, and carries the database half in the
 * clear.
 *
 * Both halves of that claim are the feature. A backup that quietly omits a
 * variable is discovered on the day somebody rebuilds an install from it and
 * push silently stops working; and a backup that carries `enc:v1:…` ciphertext
 * is a file only the machine that wrote it can use, which is every machine
 * except the one it will be restored onto.
 *
 * Against the real container and the real database, because the value under
 * test is the one the Doctrine type produced — a mock of the repository would
 * assert that this class copies a string, which is not the thing that can go
 * wrong.
 *
 * The seeded rows are made and rolled back inside one transaction, so the
 * suite's shared database is left as it was found.
 */
final class ConfigBackupExporterTest extends KernelTestCase
{
    private const string SERVICE_ACCOUNT = '{"type":"service_account","project_id":"plmail-export-test",'
        . '"client_email":"pusher@plmail-export-test.iam.gserviceaccount.com",'
        . '"private_key":"-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n"}';

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private ConfigBackupExporter $exporter;

    protected function setUp(): void
    {
        self::bootKernel();

        $container           = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->exporter      = $container->get(ConfigBackupExporter::class);
        $this->connection    = $this->entityManager->getConnection();

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();
        $this->connection->executeStatement('TRUNCATE TABLE fcm_config CASCADE');
        $this->connection->executeStatement('TRUNCATE TABLE mail_provider_config CASCADE');
        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheDocumentNamesItselfAndSaysWhenItWasMade(): void
    {
        $document = $this->exporter->document();

        self::assertSame(ConfigBackupCipher::FORMAT, $document['format']);
        self::assertSame(ConfigBackupExporter::DOCUMENT_VERSION, $document['version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $document['exportedAt']);
        self::assertArrayHasKey('instance', $document);
    }

    /**
     * Every variable in the inventory that this instance has a value for is in
     * the file, and nothing outside the inventory has crept in.
     *
     * The second half is the one worth asserting: the tempting implementation
     * sweeps $_SERVER, which on a container is a hundred entries deep and
     * includes the shell's PATH, HOSTNAME and whatever the orchestrator
     * injected.
     */
    public function testTheEnvSectionIsExactlyTheInventoryMinusWhatIsUnset(): void
    {
        $inventory = static::getContainer()->get(ConfigBackupEnvironment::class);
        $document  = $this->exporter->document();

        /** @var array<string, string> $env */
        $env = $document['env'];

        self::assertSame([], array_diff(array_keys($env), $inventory->variables()), 'nothing outside the inventory');

        // The test stack sets these two; if the harness ever stops, this
        // assertion is the thing that says so rather than a silently empty file.
        // (DATABASE_URL used to be the third - it left the inventory when the
        // database credentials stopped being exported at all.)
        foreach (['APP_SECRET', 'APP_ENCRYPTION_KEY'] as $expected) {
            self::assertArrayHasKey($expected, $env, $expected . ' is set in this environment and belongs in the file');
        }

        foreach ($env as $name => $value) {
            self::assertNotSame('', $value, $name . ' was exported as an empty string rather than omitted');
        }
    }

    /**
     * The claim the whole envelope design rests on: what comes out of the
     * database is the credential, not the ciphertext the column holds.
     */
    public function testDatabaseCredentialsLeaveDecrypted(): void
    {
        $this->seedFcmConfig();
        $this->seedMailProvider();

        /** @var array{fcmConfig: array<string, mixed>, mailProviders: array<string, array<string, mixed>>} $database */
        $database = $this->exporter->document()['database'];

        self::assertSame(self::SERVICE_ACCOUNT, $database[ConfigBackupDatabase::FCM_CONFIG]['serviceAccountJson']);
        self::assertSame('GOCSPX-a-client-secret', $database[ConfigBackupDatabase::MAIL_PROVIDERS]['google']['clientSecret']);
        self::assertSame('a-pubsub-token', $database[ConfigBackupDatabase::MAIL_PROVIDERS]['google']['pushVerificationToken']);

        // And the column really was encrypted, so the line above is a
        // decryption rather than a column that never protected anything.
        $stored = (string) $this->connection->fetchOne('SELECT service_account_json FROM fcm_config LIMIT 1');
        self::assertStringStartsWith('enc:v1:', $stored);
    }

    public function testAProviderWithNoRowIsAbsentRatherThanNull(): void
    {
        $this->seedMailProvider();

        /** @var array{mailProviders: array<string, mixed>} $database */
        $database = $this->exporter->document()['database'];

        self::assertArrayHasKey('google', $database[ConfigBackupDatabase::MAIL_PROVIDERS]);
        self::assertArrayNotHasKey('microsoft', $database[ConfigBackupDatabase::MAIL_PROVIDERS]);
    }

    public function testTheSealedFileIsOpenableAndTheFilenameIsDated(): void
    {
        $sealed = $this->exporter->export('a long enough password');

        self::assertSame(
            ConfigBackupCipher::FORMAT,
            static::getContainer()->get(ConfigBackupCipher::class)->open($sealed, 'a long enough password')['format'],
        );

        self::assertMatchesRegularExpression('/^plmail-config-\d{4}-\d{2}-\d{2}\.backup$/', $this->exporter->filename());
    }

    // ── Seeding ───────────────────────────────────────────────────────────────

    private function seedFcmConfig(): void
    {
        $config = new FcmConfig();
        $config->restore(self::SERVICE_ACCOUNT, 'plmail-export-test', '1:1:android:abc', 'AIza-key', '1', 'test.plmail', true);

        $this->entityManager->persist($config);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function seedMailProvider(): void
    {
        $config                        = new MailProviderConfig(MailProvider::Google);
        $config->clientId              = 'a-client-id.apps.googleusercontent.com';
        $config->clientSecret          = 'GOCSPX-a-client-secret';
        $config->pushVerificationToken = 'a-pubsub-token';
        $config->pubsubTopic           = 'projects/plmail/topics/gmail-push';

        $this->entityManager->persist($config);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
