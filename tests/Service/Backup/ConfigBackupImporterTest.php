<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Domain\DTO\Backup\ConfigBackupPlan;
use App\Domain\DTO\Backup\ConfigBackupPlanItem;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Backup\ConfigBackupChange;
use App\Domain\Enum\Backup\ConfigBackupFailure;
use App\Domain\Enum\Backup\ConfigBackupObstacle;
use App\Domain\Enum\Backup\ConfigBackupSection;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\ConfigBackupException;
use App\Entity\Push\FcmConfig;
use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\MailProviderConfigRepository;
use App\Repository\Push\FcmConfigRepository;
use App\Service\Backup\ConfigBackupDatabase;
use App\Service\Backup\ConfigBackupExporter;
use App\Service\Backup\ConfigBackupFiles;
use App\Service\Backup\ConfigBackupImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An import applies what is genuinely plMail's to write, says so honestly about
 * everything else, and re-encrypts what it writes under the key in force here.
 *
 * The last part is the reason this feature exists and is the only claim here
 * that cannot be checked by reading the code: the exporting install and the
 * importing one have different APP_ENCRYPTION_KEYs by construction — a fresh
 * install mints its own at first start — so a restore that moved ciphertext
 * would produce rows that decrypt to nothing on the first read. The test swaps
 * the Encryptor between export and import through the same static seam
 * Kernel::boot uses, which is as close to "a different machine" as one process
 * gets, and then proves the stored bytes are unreadable with the OLD key. That
 * last assertion is what makes this a test rather than a round trip: a version
 * of the importer that wrote the ciphertext straight through would satisfy
 * everything above it.
 *
 * The classification half is asserted per section rather than by counting.
 * "Sixteen automatic, four manual" passes for an importer that put
 * APP_ENCRYPTION_KEY in the wrong list, and that mistake is the one with the
 * worst consequence in the whole feature.
 */
final class ConfigBackupImporterTest extends KernelTestCase
{
    private const string SERVICE_ACCOUNT = '{"type":"service_account","project_id":"plmail-import-test",'
        . '"client_email":"pusher@plmail-import-test.iam.gserviceaccount.com",'
        . '"private_key":"-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n"}';

    /** A second install's key: 32 bytes, base64, and not the one the test stack runs on. */
    private const string OTHER_KEY = 'YW5vdGhlciBpbnN0YWxsYXRpb25zIGtleSwgMzIgYnk=';

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private ConfigBackupImporter $importer;

    private ConfigBackupExporter $exporter;

    private Encryptor $originalEncryptor;

    protected function setUp(): void
    {
        self::bootKernel();

        $container               = static::getContainer();
        $this->entityManager     = $container->get(EntityManagerInterface::class);
        $this->importer          = $container->get(ConfigBackupImporter::class);
        $this->exporter          = $container->get(ConfigBackupExporter::class);
        $this->originalEncryptor = $container->get(Encryptor::class);
        $this->connection        = $this->entityManager->getConnection();

        $this->connection->beginTransaction();

        foreach (['fcm_config', 'mail_provider_config', 'integration_provider_config'] as $table) {
            $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
        }

        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        // Before the rollback: a test that swapped the key must not leave the
        // static type holding it, or every suite that runs after this one in
        // the same process reads the database with the wrong one.
        EncryptedStringType::setEncryptor($this->originalEncryptor);

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Export here, throw the key away, import as a different installation
     * would, and read the credential back.
     */
    public function testFirebaseCredentialsSurviveAnImportIntoAStoreWithADifferentKey(): void
    {
        $this->seedFcmConfig();

        $document = $this->exporter->document();

        // Everything past this line is "the other install": a different key,
        // and nothing configured.
        $this->becomeADifferentInstallation();

        $plan = $this->importer->apply($document);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $restored = static::getContainer()->get(FcmConfigRepository::class)->current();

        self::assertNotNull($restored, 'the row was not created');
        self::assertSame(self::SERVICE_ACCOUNT, $restored->serviceAccountJson, 'the key did not survive the key change');
        self::assertSame('plmail-import-test', $restored->projectId);
        self::assertSame('pusher@plmail-import-test.iam.gserviceaccount.com', $restored->clientEmail);
        self::assertTrue($restored->isActive(), 'a restored install must come back live, not half-configured');

        self::assertSame(
            ConfigBackupChange::Absent,
            $this->itemFor($plan, ConfigBackupSection::Database, ConfigBackupDatabase::FCM_CONFIG)->change,
        );

        // The proof that it was re-encrypted rather than copied: the bytes in
        // the column are unreadable to the install that made the backup.
        $stored = (string) $this->connection->fetchOne('SELECT service_account_json FROM fcm_config LIMIT 1');

        self::assertStringStartsWith('enc:v1:', $stored);
        self::expectExceptionMessageMatches('/APP_ENCRYPTION_KEY has changed/');
        $this->originalEncryptor->decrypt($stored);
    }

    /**
     * The same for the two provider tables, since they carry the credentials an
     * install is most likely to be restored FOR.
     */
    public function testProviderRegistrationsSurviveTheSameJourney(): void
    {
        $document = [
            'format'   => 'plmail-config-backup',
            'version'  => 1,
            'database' => [
                ConfigBackupDatabase::MAIL_PROVIDERS => [
                    'google' => [
                        'clientId'              => 'an-id.apps.googleusercontent.com',
                        'clientSecret'          => 'GOCSPX-secret',
                        'pushVerificationToken' => 'a-pubsub-token',
                        'settings'              => ['pubsub.topic' => 'projects/x/topics/y'],
                    ],
                ],
                ConfigBackupDatabase::INTEGRATION_PROVIDERS => [
                    'nextcloud' => [
                        'isEnabled'    => true,
                        'baseUrl'      => 'https://cloud.example.test',
                        'clientId'     => null,
                        'clientSecret' => null,
                        'settings'     => [],
                    ],
                ],
            ],
        ];

        $this->becomeADifferentInstallation();

        $this->importer->apply($document);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $google = static::getContainer()->get(MailProviderConfigRepository::class)->findOneByProvider(MailProvider::Google);

        self::assertNotNull($google);
        self::assertSame('GOCSPX-secret', $google->clientSecret);
        self::assertSame('a-pubsub-token', $google->pushVerificationToken);
        self::assertSame('projects/x/topics/y', $google->pubsubTopic);

        $nextcloud = static::getContainer()->get(IntegrationProviderConfigRepository::class)->findOneByProvider(Provider::Nextcloud);

        self::assertNotNull($nextcloud);
        self::assertTrue($nextcloud->isEnabled);
        self::assertSame('https://cloud.example.test', $nextcloud->baseUrl);
    }

    /**
     * Every environment value is manual, and the two whose mishandling is worst
     * carry their own reason rather than the generic one.
     */
    public function testEveryEnvironmentValueIsManualAndCarriesTheRightReason(): void
    {
        $plan = $this->importer->plan([
            'env' => [
                'APP_ENCRYPTION_KEY' => 'c29tZS1vdGhlci1rZXktdGhpcnR5LXR3by1ieXRlcw==',
                'APP_SECRET'         => 'deadbeef',
                'POSTGRES_PASSWORD'  => 'hunter2',
                'VAPID_PRIVATE_KEY'  => 'a-vapid-private-key',
            ],
        ]);

        foreach ($plan->items as $item) {
            self::assertFalse($item->isAutomatic(), $item->key . ' must never be written by an import');
        }

        self::assertSame(
            ConfigBackupObstacle::EncryptionKeyInUse,
            $this->itemFor($plan, ConfigBackupSection::Environment, 'APP_ENCRYPTION_KEY')->obstacle,
        );
        self::assertSame(
            ConfigBackupObstacle::ExternalSystem,
            $this->itemFor($plan, ConfigBackupSection::Environment, 'POSTGRES_PASSWORD')->obstacle,
        );
        self::assertSame(
            ConfigBackupObstacle::ProcessEnvironment,
            $this->itemFor($plan, ConfigBackupSection::Environment, 'VAPID_PRIVATE_KEY')->obstacle,
        );
    }

    /**
     * The instruction is the deliverable for a manual item, so it has to be the
     * literal line — quoted when the value would otherwise be mangled by the
     * dotenv parser, which is the case that actually bites.
     */
    public function testManualInstructionsArePasteableLines(): void
    {
        $plan = $this->importer->plan([
            'env' => [
                'APP_SECRET' => 'deadbeef',
                'MAILER_DSN' => 'smtp://user:pa ss#word@host:587',
            ],
        ]);

        self::assertSame('APP_SECRET=deadbeef', $this->itemFor($plan, ConfigBackupSection::Environment, 'APP_SECRET')->instruction);
        self::assertSame(
            'MAILER_DSN="smtp://user:pa ss#word@host:587"',
            $this->itemFor($plan, ConfigBackupSection::Environment, 'MAILER_DSN')->instruction,
        );
    }

    /**
     * The postgres password file is the case that separates "can we write it"
     * from "should we": a writable secrets volume must not make it automatic,
     * because the other half of the change is a role inside a database.
     */
    public function testThePostgresPasswordFileStaysManualEvenWhereItIsWritable(): void
    {
        $files = static::getContainer()->get(ConfigBackupFiles::class);

        $plan = $this->importer->plan([
            'files' => [ConfigBackupFiles::POSTGRES_PASSWORD => base64_encode('a-password')],
        ]);

        $item = $this->itemFor($plan, ConfigBackupSection::SecretsFile, ConfigBackupFiles::POSTGRES_PASSWORD);

        self::assertSame(ConfigBackupObstacle::ExternalSystem, $item->obstacle);
        self::assertSame($files->pathFor(ConfigBackupFiles::POSTGRES_PASSWORD), $item->instruction);
    }

    /**
     * A file is automatic exactly when this process can write it — measured,
     * not assumed. Both directions, so an implementation that hard-coded either
     * answer fails one of them.
     */
    public function testAFileIsAutomaticOnlyWhereThisProcessCanWriteIt(): void
    {
        $files = static::getContainer()->get(ConfigBackupFiles::class);

        self::assertTrue(
            $files->isWritable(ConfigBackupFiles::JWT_PRIVATE),
            'the fixture is only meaningful where the secrets directory can be written',
        );

        $plan = $this->importer->plan([
            'files' => [ConfigBackupFiles::JWT_PRIVATE => base64_encode("-----BEGIN PRIVATE KEY-----\n")],
        ]);

        self::assertTrue($this->itemFor($plan, ConfigBackupSection::SecretsFile, ConfigBackupFiles::JWT_PRIVATE)->isAutomatic());

        // And the other direction, which this stack cannot produce but every
        // read-only secrets mount does: the same class, pointed under a
        // directory nothing can create a file in, must say so rather than
        // promise a write it would fail at.
        //
        // /sys, because it is read-only even to uid 0 — which this container
        // is, so a chmod-ed temporary directory would prove nothing.
        $readOnly = new ConfigBackupFiles('/sys/plmail-nowhere/jwt/private.pem', '/sys/plmail-nowhere/jwt/public.pem', '/sys/plmail-nowhere/generated.env');

        self::assertFalse($readOnly->isWritable(ConfigBackupFiles::JWT_PRIVATE));
        self::assertSame(ConfigBackupObstacle::NotWritable, $readOnly->obstacleFor(ConfigBackupFiles::JWT_PRIVATE));
    }

    /**
     * A restore onto a configured install has to say which values it is
     * replacing. Showing "will be applied" without that distinction is how an
     * admin overwrites a live Firebase key believing they are filling a gap.
     */
    public function testAValueThatAlreadyMatchesIsUnchangedAndOneThatDoesNotDiffers(): void
    {
        $this->seedFcmConfig();

        $document = $this->exporter->document();

        self::assertSame(
            ConfigBackupChange::Unchanged,
            $this->itemFor($this->importer->plan($document), ConfigBackupSection::Database, ConfigBackupDatabase::FCM_CONFIG)->change,
        );

        /** @var array{database: array<string, mixed>} $document */
        $document['database'][ConfigBackupDatabase::FCM_CONFIG]['apiKey'] = 'AIza-a-different-key';

        self::assertSame(
            ConfigBackupChange::Differs,
            $this->itemFor($this->importer->plan($document), ConfigBackupSection::Database, ConfigBackupDatabase::FCM_CONFIG)->change,
        );
    }

    /** A provider this build has no enum case for is skipped, not fatal. */
    public function testAProviderFromANewerPlmailIsSkippedRatherThanFailingTheImport(): void
    {
        $plan = $this->importer->plan([
            'database' => [
                ConfigBackupDatabase::INTEGRATION_PROVIDERS => [
                    'nextcloud'      => ['isEnabled' => true],
                    'somethingNewer' => ['isEnabled' => true],
                ],
            ],
        ]);

        $keys = array_map(static fn (ConfigBackupPlanItem $item): string => $item->key, $plan->items);

        self::assertContains(ConfigBackupDatabase::INTEGRATION_PROVIDERS . '.nextcloud', $keys);
        self::assertNotContains(ConfigBackupDatabase::INTEGRATION_PROVIDERS . '.somethingNewer', $keys);
    }

    /**
     * open() is the only thing standing between a stranger's file and the
     * planner, so it refuses a document that decrypted successfully but is not
     * one of ours.
     */
    public function testADecryptedDocumentThatIsNotOursIsRefused(): void
    {
        $cipher = static::getContainer()->get(ConfigBackupCipher::class);
        $sealed = $cipher->seal(['format' => 'something-else', 'version' => 1], 'a long enough password');

        try {
            $this->importer->open($sealed, 'a long enough password');

            self::fail('a foreign document was accepted');
        } catch (ConfigBackupException $e) {
            self::assertSame(ConfigBackupFailure::MalformedDocument, $e->failure);
        }
    }

    /**
     * A wrong password stops at the envelope, so nothing downstream ever sees a
     * document and nothing is written.
     */
    public function testAWrongPasswordWritesNothing(): void
    {
        $this->seedFcmConfig();

        $sealed = $this->exporter->export('the right password entirely');

        $this->connection->executeStatement('TRUNCATE TABLE fcm_config CASCADE');
        $this->entityManager->clear();

        try {
            $this->importer->apply($this->importer->open($sealed, 'the wrong password entirely'));

            self::fail('a wrong password was accepted');
        } catch (ConfigBackupException $e) {
            self::assertSame(ConfigBackupFailure::WrongPassword, $e->failure);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM fcm_config'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Everything a different machine would have: another encryption key, and
     * none of this one's rows.
     *
     * The static seam is the one Kernel::boot() uses — see EncryptedStringType,
     * which documents why Doctrine leaves no other way in. Swapping it here is
     * what makes "a store with a different encryption key" a thing one process
     * can be.
     */
    private function becomeADifferentInstallation(): void
    {
        EncryptedStringType::setEncryptor(new Encryptor(self::OTHER_KEY));

        foreach (['fcm_config', 'mail_provider_config', 'integration_provider_config'] as $table) {
            $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
        }

        $this->entityManager->clear();
    }

    private function seedFcmConfig(): void
    {
        $config = new FcmConfig();
        $config->restore(self::SERVICE_ACCOUNT, 'plmail-import-test', '1:1:android:abc', 'AIza-key', '1', 'test.plmail', true);

        $this->entityManager->persist($config);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function itemFor(ConfigBackupPlan $plan, ConfigBackupSection $section, string $key): ConfigBackupPlanItem
    {
        foreach ($plan->items as $item) {
            if ($section === $item->section && $key === $item->key) {
                return $item;
            }
        }

        self::fail(sprintf('the plan has no %s item for %s', $section->value, $key));
    }
}
