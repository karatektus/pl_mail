<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Domain\DTO\Backup\ConfigBackupPlan;
use App\Domain\DTO\Backup\ConfigBackupPlanItem;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Backup\ConfigBackupChange;
use App\Domain\Enum\Backup\ConfigBackupFailure;
use App\Domain\Enum\Backup\ConfigBackupDisposition;
use App\Domain\Enum\Backup\ConfigBackupSection;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\ConfigBackupException;
use App\Entity\Push\FcmConfig;
use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Infrastructure\Setup\ProcessEnvironment;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\MailProviderConfigRepository;
use App\Repository\Push\FcmConfigRepository;
use App\Service\Backup\ConfigBackupDatabase;
use App\Service\Backup\ConfigBackupEnvironment;
use App\Service\Backup\ConfigBackupExporter;
use App\Service\Backup\ConfigBackupFiles;
use App\Service\Backup\ConfigBackupImporter;
use App\Service\Backup\ConfigBackupUserRestorer;
use App\Service\Backup\ConfigBackupUsers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
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
 * The classification half is asserted per disposition rather than by counting —
 * with one exception, and it is deliberate: the fresh-install path asserts that
 * `instructed()` is *empty*, because the number that matters there is zero and
 * a change that quietly makes it one has to fail rather than pass with a
 * different list.
 *
 * The classification tests run against a secrets store of their own, under a
 * temporary directory, with a stubbed process environment. Not for isolation's
 * sake: the answers depend entirely on what is in `generated.env` and what is
 * in `getenv()`, and a test that read the real ones would assert whatever the
 * container it happened to run in was configured with.
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

    /** A secrets volume of this test's own; see the class docblock. */
    private string $secretsDirectory;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->secretsDirectory = sys_get_temp_dir() . '/plmail-backup-' . bin2hex(random_bytes(6));

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

        $this->removeDirectory($this->secretsDirectory);

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
     * The headline case: a fresh instance, a backup, and nothing left for the
     * operator to do.
     *
     * This is the assertion the whole rework is judged by, and it is written as
     * a count of zero rather than as a list of dispositions on purpose — a
     * future change that quietly sends one more value back to the operator has
     * to fail here and be argued for, which is exactly what happened to the
     * version of this feature that classified all twenty-six of them as
     * "paste this into .env.local".
     *
     * The instance is fresh in the sense that matters: a generated secrets file
     * this process can write, and nothing pinned in the environment over the
     * top of it.
     */
    public function testAFreshInstanceAppliesEverythingAndInstructsNothing(): void
    {
        $importer = $this->importerWith(processEnvironment: []);

        $plan = $importer->apply([
            'env' => [
                // APP_SECRET used to stand here, and was the headline name of
                // the whole section. It left the inventory for a reason none of
                // the others share: it protects nothing durable on this install
                // — remember-me is signature-based, sessions and CSRF die with
                // the session, JMAP signs with the pems — so the one thing
                // restoring it did was keep the source machine's browser
                // cookies verifying here. Now KeptDeliberately, which means an
                // old backup carrying it belongs to the vocabulary test below.
                // MERCURE_JWT_SECRET used to stand here too. It left the
                // inventory the way the DSNs did — it pairs the app with the
                // hub container beside it, and the hub reads it only at
                // container start — so an old backup carrying it is now the
                // vocabulary test's business, not this one's.
                'VAPID_PUBLIC_KEY'   => 'a-restored-vapid-public-key',
                'VAPID_PRIVATE_KEY'  => 'a-restored-vapid-private-key',
                'APP_PUBLIC_URL'     => 'https://mail.example.test',
                // MERCURE_PUBLIC_URL rather than MAILER_DSN, which used to
                // stand here: the two DSNs left the inventory and are refused
                // as External now, so this one is the remaining name that
                // compose pins on a stock stack and that a backup still
                // carries. Nothing is pinned in THIS test's environment, so it
                // applies cleanly, which is the case being asserted.
                'MERCURE_PUBLIC_URL' => 'https://mercure.example.test/.well-known/mercure',
            ],
            'files' => [
                ConfigBackupFiles::JWT_PRIVATE => base64_encode("-----BEGIN PRIVATE KEY-----\nrestored\n"),
                ConfigBackupFiles::JWT_PUBLIC  => base64_encode("-----BEGIN PUBLIC KEY-----\nrestored\n"),
            ],
        ]);

        self::assertSame([], $plan->instructed(), 'a fresh instance must have nothing left to hand back');
        self::assertCount(6, $plan->written());
        self::assertTrue($plan->needsRestart(), 'these are read at container start, and the page has to say so once');

        // And the values are genuinely where the entrypoint will read them,
        // rather than merely reported as written. Asserted in order, because
        // the order is the inventory's — the file is written from the plan, and
        // a plan that walked the document instead would land in whatever order
        // the JSON happened to have.
        self::assertSame(
            [
                'MERCURE_PUBLIC_URL' => 'https://mercure.example.test/.well-known/mercure',
                'APP_PUBLIC_URL'     => 'https://mail.example.test',
                'VAPID_PUBLIC_KEY'   => 'a-restored-vapid-public-key',
                'VAPID_PRIVATE_KEY'  => 'a-restored-vapid-private-key',
            ],
            (new GeneratedSecretsFile($this->secretsPath()))->read(),
        );
    }

    /**
     * One case per word in the review's vocabulary, in one test, because the
     * vocabulary is only meaningful as a set: an implementation that answered
     * `AppliedOnRestart` to everything would pass any five of these taken
     * alone.
     */
    public function testEachDispositionIsReachedByTheCaseItDescribes(): void
    {
        $importer = $this->importerWith(processEnvironment: [
            // Pinned in the process environment and absent from the generated
            // file: exactly what compose.yaml does to MERCURE_PUBLIC_URL — and
            // to the two DSNs, which no longer reach this fate because they are
            // refused before shadowing is asked about. See below.
            'MERCURE_PUBLIC_URL' => 'https://mercure.compose.test/.well-known/mercure',
        ]);

        $plan = $importer->plan([
            'env' => [
                // JWT_PASSPHRASE rather than the APP_SECRET this used to use:
                // that one is KeptDeliberately now and is asserted as such
                // below, so it can no longer stand for the ordinary case.
                'JWT_PASSPHRASE'          => 'a-restored-passphrase',
                'APP_SECRET'              => 'a-restored-app-secret',
                'MERCURE_PUBLIC_URL'      => 'https://mercure.backup.test/.well-known/mercure',
                'APP_ENCRYPTION_KEY'      => 'c29tZS1vdGhlci1rZXktdGhpcnR5LXR3by1ieXRlcw==',
                'POSTGRES_PASSWORD'       => 'hunter2',
                'DATABASE_URL'            => 'postgresql://app:hunter2@old-host:5432/app',
                // Only an OLD backup carries these two; a document written by
                // this build has neither. They are here because that is exactly
                // the file this classification exists for.
                'MAILER_DSN'              => 'smtp://relay.example.test',
                'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?auto_setup=0',
                'MERCURE_JWT_SECRET'      => 'a-restored-hub-secret',
                'TRUSTED_PROXIES'         => '10.9.0.0/16',
            ],
            'database' => [
                ConfigBackupDatabase::MAIL_PROVIDERS => ['google' => ['clientId' => 'an-id']],
            ],
        ]);

        $expected = [
            [ConfigBackupSection::Database, ConfigBackupDatabase::MAIL_PROVIDERS . '.google', ConfigBackupDisposition::Applied],
            [ConfigBackupSection::Environment, 'JWT_PASSPHRASE', ConfigBackupDisposition::AppliedOnRestart],
            [ConfigBackupSection::Environment, 'MERCURE_PUBLIC_URL', ConfigBackupDisposition::ShadowedByCompose],
            [ConfigBackupSection::Environment, 'POSTGRES_PASSWORD', ConfigBackupDisposition::External],
            [ConfigBackupSection::Environment, 'DATABASE_URL', ConfigBackupDisposition::External],
            [ConfigBackupSection::Environment, 'MAILER_DSN', ConfigBackupDisposition::External],
            [ConfigBackupSection::Environment, 'MESSENGER_TRANSPORT_DSN', ConfigBackupDisposition::External],
            [ConfigBackupSection::Environment, 'MERCURE_JWT_SECRET', ConfigBackupDisposition::External],
            [ConfigBackupSection::Environment, 'TRUSTED_PROXIES', ConfigBackupDisposition::External],
            [ConfigBackupSection::Environment, 'APP_ENCRYPTION_KEY', ConfigBackupDisposition::KeptDeliberately],
            // The second name the target keeps its own of, and the only one
            // whose reason is about what a restored value would let somebody
            // ELSE do: a cookie signed on the source machine stays valid here.
            [ConfigBackupSection::Environment, 'APP_SECRET', ConfigBackupDisposition::KeptDeliberately],
        ];

        foreach ($expected as [$section, $key, $disposition]) {
            self::assertSame($disposition, $this->itemFor($plan, $section, $key)->disposition, $key);
        }

        // The sixth word, which no fixture on a working stack produces: a
        // secrets store this process cannot write. /sys is read-only even to
        // uid 0, which this container is, so a chmod-ed temporary directory
        // would prove nothing.
        $unwritable = $this->importerWith(processEnvironment: [], secretsDirectory: '/sys/plmail-nowhere');

        self::assertSame(
            ConfigBackupDisposition::NotWritable,
            $this->itemFor(
                // Not APP_SECRET: a refusal is answered before anybody asks
                // whether the file is writable, so a refused name here would
                // report KeptDeliberately and prove nothing about the store.
                $unwritable->plan(['env' => ['JWT_PASSPHRASE' => 'a-restored-passphrase']]),
                ConfigBackupSection::Environment,
                'JWT_PASSPHRASE',
            )->disposition,
        );
    }

    /**
     * The crux of the whole classification, and the one thing that cannot be
     * read off the code: telling a value compose pinned from a value the
     * entrypoint exported out of the generated file.
     *
     * `frankenphp/docker-entrypoint.sh` exports every line of `generated.env`
     * into the real environment before it execs the server, so "is it in
     * getenv" answers yes for both and distinguishes nothing. What separates
     * them is whether the file could have produced the live value — and both
     * directions are asserted here, because an implementation that flagged
     * everything as shadowed would restore an install and then tell its owner
     * that none of it counted.
     */
    public function testTheEntrypointsOwnExportIsNotMistakenForAComposePin(): void
    {
        (new GeneratedSecretsFile($this->secretsPath()))->setMany([
            'APP_SECRET'         => 'what-this-install-generated',
            'MERCURE_JWT_SECRET' => 'the-hub-secret',
        ]);

        $environment = $this->environmentWith([
            // As the entrypoint left it: the file's own value, re-exported.
            'APP_SECRET'              => 'what-this-install-generated',
            // As compose leaves it: a name the file has never held.
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?auto_setup=0',
            // As an operator leaves it: the file has a value and something in
            // the environment disagrees with it.
            'MERCURE_JWT_SECRET'      => 'what the operator pinned instead',
            // Empty counts as absent — compose passes ${APP_PUBLIC_URL:-}
            // through as "" when nobody set one, and treating that as pinned
            // would flag every generated secret on every install.
            'APP_PUBLIC_URL'          => '',
        ]);

        self::assertFalse($environment->isShadowed('APP_SECRET'));
        self::assertFalse($environment->isShadowed('APP_PUBLIC_URL'));
        self::assertFalse($environment->isShadowed('VAPID_PUBLIC_KEY'), 'a name nothing sets is not shadowed either');

        self::assertTrue($environment->isShadowed('MESSENGER_TRANSPORT_DSN'));
        self::assertTrue($environment->isShadowed('MERCURE_JWT_SECRET'));
    }

    /**
     * A shadowed value is written anyway, and still reported.
     *
     * Both halves matter. Writing it means that the moment the operator drops
     * the pin from their compose file the restored value is already underneath
     * — one step instead of two. Reporting it means they are never told a
     * restore took effect when the next container start will overwrite it,
     * which is the honest residue of the old instruction wall and the only
     * thing left of it.
     */
    public function testAShadowedValueIsWrittenAndStillHandedBack(): void
    {
        $pinned   = 'https://mercure.compose.test/.well-known/mercure';
        $restored = 'https://mercure.backup.test/.well-known/mercure';

        $importer = $this->importerWith(processEnvironment: ['MERCURE_PUBLIC_URL' => $pinned]);

        $plan = $importer->apply(['env' => ['MERCURE_PUBLIC_URL' => $restored]]);

        $item = $this->itemFor($plan, ConfigBackupSection::Environment, 'MERCURE_PUBLIC_URL');

        self::assertSame(ConfigBackupDisposition::ShadowedByCompose, $item->disposition);
        self::assertSame('MERCURE_PUBLIC_URL=' . $restored, $item->instruction, 'the line to change in compose');
        self::assertSame([$item], $plan->instructed());

        self::assertSame(
            ['MERCURE_PUBLIC_URL' => $restored],
            (new GeneratedSecretsFile($this->secretsPath()))->read(),
            'the value goes in regardless, so removing the pin is the only step left',
        );
    }

    /**
     * The JWT keypair is the file half, and overwriting is the whole job: every
     * service in the stack has to verify tokens the others signed, so an
     * install that kept its own generated pair would reject every JMAP session
     * the restored one had issued.
     */
    public function testTheJwtKeypairIsOverwrittenWithTheOneFromTheBackup(): void
    {
        $files = $this->filesFor($this->secretsDirectory);

        $files->write(ConfigBackupFiles::JWT_PRIVATE, "-----BEGIN PRIVATE KEY-----\nthis instance generated this one\n");

        $restored = "-----BEGIN PRIVATE KEY-----\nthe one from the backup\n";

        $plan = $this->importerWith(processEnvironment: [])->apply([
            'files' => [ConfigBackupFiles::JWT_PRIVATE => base64_encode($restored)],
        ]);

        $item = $this->itemFor($plan, ConfigBackupSection::SecretsFile, ConfigBackupFiles::JWT_PRIVATE);

        self::assertSame(ConfigBackupChange::Differs, $item->change);
        self::assertSame(ConfigBackupDisposition::AppliedOnRestart, $item->disposition);
        self::assertNull($item->instruction, 'nothing is asked of an operator whose keypair plMail just replaced');

        self::assertSame($restored, $files->read(ConfigBackupFiles::JWT_PRIVATE));
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($files->pathFor(ConfigBackupFiles::JWT_PRIVATE))), -4));
    }

    /**
     * The postgres password separates "can we write it" from "should we".
     *
     * `generate-secrets.sh` rewrites the bare `postgres_password` file from
     * `generated.env` on every boot, so it would stay in step — but the
     * Postgres image reads `POSTGRES_PASSWORD_FILE` only at initdb, and on a
     * database that already exists the ROLE keeps the password it was created
     * with. A writable secrets volume is therefore no argument for writing it,
     * and this asserts that the file is left exactly as it was.
     */
    public function testThePostgresPasswordIsRefusedEvenWhereItIsWritable(): void
    {
        $files = $this->filesFor($this->secretsDirectory);

        $files->write(ConfigBackupFiles::POSTGRES_PASSWORD, 'what-this-stack-was-initialised-with');

        $importer = $this->importerWith(processEnvironment: []);

        $plan = $importer->apply([
            'env'   => ['POSTGRES_PASSWORD' => 'the-old-hosts-password'],
            'files' => [ConfigBackupFiles::POSTGRES_PASSWORD => base64_encode('the-old-hosts-password')],
        ]);

        foreach ([
            $this->itemFor($plan, ConfigBackupSection::Environment, 'POSTGRES_PASSWORD'),
            $this->itemFor($plan, ConfigBackupSection::SecretsFile, ConfigBackupFiles::POSTGRES_PASSWORD),
        ] as $item) {
            self::assertSame(ConfigBackupDisposition::External, $item->disposition, $item->key);
            self::assertNotNull($item->instruction);
        }

        self::assertSame('what-this-stack-was-initialised-with', $files->read(ConfigBackupFiles::POSTGRES_PASSWORD));
        self::assertSame([], (new GeneratedSecretsFile($this->secretsPath()))->read());
    }

    /**
     * APP_ENCRYPTION_KEY is the one value whose *not* being written is the
     * correct outcome rather than a limitation, so it is a note and not a
     * chore: the import re-encrypted the backup's credentials under the key in
     * force here, and putting the backup's key in place would make the rows it
     * just wrote unreadable at the next start.
     *
     * The line is still offered, because an operator restoring the old database
     * alongside this backup genuinely needs it — but it is not counted among
     * the things left to do, which is what keeps the fresh-install path clean.
     */
    public function testTheEncryptionKeyIsANoteRatherThanAChore(): void
    {
        $plan = $this->importerWith(processEnvironment: [])->apply([
            'env' => ['APP_ENCRYPTION_KEY' => 'c29tZS1vdGhlci1rZXktdGhpcnR5LXR3by1ieXRlcw=='],
        ]);

        $item = $this->itemFor($plan, ConfigBackupSection::Environment, 'APP_ENCRYPTION_KEY');

        self::assertSame(ConfigBackupDisposition::KeptDeliberately, $item->disposition);
        self::assertSame([$item], $plan->notes());
        self::assertSame([], $plan->instructed());
        self::assertSame([], $plan->written());
        self::assertSame(
            'APP_ENCRYPTION_KEY=c29tZS1vdGhlci1rZXktdGhpcnR5LXR3by1ieXRlcw==',
            $item->instruction,
        );

        self::assertSame([], (new GeneratedSecretsFile($this->secretsPath()))->read());
    }

    /**
     * A secrets store this process cannot write falls all the way back to what
     * the feature used to do for everything: the exact lines to paste.
     *
     * Demoted rather than thrown, and demoted as one batch, because the write
     * is one operation — there is no partial outcome to report, and an import
     * that threw here would leave the operator with a committed database and no
     * account of the rest.
     */
    public function testAnUnwritableSecretsStoreFallsBackToLinesToPaste(): void
    {
        $plan = $this->importerWith(processEnvironment: [], secretsDirectory: '/sys/plmail-nowhere')->apply([
            'env' => ['VAPID_PRIVATE_KEY' => 'a-restored-vapid-private-key', 'JWT_PASSPHRASE' => 'a pass#phrase with spaces'],
        ]);

        self::assertCount(2, $plan->instructed());
        self::assertSame([], $plan->written());

        // VAPID_PRIVATE_KEY rather than the APP_SECRET this used to use: that
        // one is refused now, and a refusal is decided before the store's
        // writability is ever asked about, so it would be handed back for the
        // wrong reason and this test would pass without testing anything.
        self::assertSame(
            'VAPID_PRIVATE_KEY=a-restored-vapid-private-key',
            $this->itemFor($plan, ConfigBackupSection::Environment, 'VAPID_PRIVATE_KEY')->instruction,
        );

        // Quoted, because Symfony's dotenv would read the `#` as a comment and
        // hand the operator a truncated passphrase with no error anywhere.
        // (JWT_PASSPHRASE rather than the MAILER_DSN this used to use: that one
        // is External now and would be handed back for a different reason,
        // which would make this assertion pass without testing anything.)
        self::assertSame(
            'JWT_PASSPHRASE="a pass#phrase with spaces"',
            $this->itemFor($plan, ConfigBackupSection::Environment, 'JWT_PASSPHRASE')->instruction,
        );
    }

    /**
     * A value already identical to what is here is not rewritten, and does not
     * make the page ask for a restart. Re-importing the same backup twice has
     * to be a no-op the second time, or "restart the stack" becomes noise an
     * operator learns to ignore.
     */
    public function testAnUnchangedValueIsNeitherWrittenNorAReasonToRestart(): void
    {
        (new GeneratedSecretsFile($this->secretsPath()))->setMany(['JWT_PASSPHRASE' => 'already-this']);

        $plan = $this->importerWith(processEnvironment: [])->plan(['env' => ['JWT_PASSPHRASE' => 'already-this']]);

        self::assertSame(ConfigBackupChange::Unchanged, $this->itemFor($plan, ConfigBackupSection::Environment, 'JWT_PASSPHRASE')->change);
        self::assertFalse($plan->needsRestart());
        self::assertFalse($plan->hasMaterialChanges());
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
     * An importer whose secrets store and process environment are this test's,
     * not the container's.
     *
     * Assembled by hand rather than pulled from the container, because the two
     * collaborators being substituted are precisely the two the classification
     * reads — and a test that took the real ones would be asserting the shape
     * of whatever CI image it ran inside.
     *
     * @param array<string, string> $processEnvironment
     */
    private function importerWith(array $processEnvironment, ?string $secretsDirectory = null): ConfigBackupImporter
    {
        $directory = $secretsDirectory ?? $this->secretsDirectory;

        return new ConfigBackupImporter(
            static::getContainer()->get(ConfigBackupCipher::class),
            $this->environmentWith($processEnvironment, $directory),
            $this->filesFor($directory),
            static::getContainer()->get(ConfigBackupDatabase::class),
            static::getContainer()->get(ConfigBackupUsers::class),
            static::getContainer()->get(ConfigBackupUserRestorer::class),
            $this->entityManager,
            new NullLogger(),
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function environmentWith(array $values, ?string $secretsDirectory = null): ConfigBackupEnvironment
    {
        return new ConfigBackupEnvironment(
            new GeneratedSecretsFile(($secretsDirectory ?? $this->secretsDirectory) . '/generated.env'),
            // The contract RealProcessEnvironment implements over getenv():
            // trimmed, and empty means absent.
            new class($values) implements ProcessEnvironment {
                /** @param array<string, string> $values */
                public function __construct(private readonly array $values)
                {
                }

                public function get(string $name): ?string
                {
                    $value = trim($this->values[$name] ?? '');

                    return '' === $value ? null : $value;
                }
            },
        );
    }

    private function filesFor(string $secretsDirectory): ConfigBackupFiles
    {
        return new ConfigBackupFiles(
            $secretsDirectory . '/jwt/private.pem',
            $secretsDirectory . '/jwt/public.pem',
            $secretsDirectory . '/generated.env',
        );
    }

    private function secretsPath(): string
    {
        return $this->secretsDirectory . '/generated.env';
    }

    private function removeDirectory(string $directory): void
    {
        if (false === is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if (false === is_string($entry) || '.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }

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
