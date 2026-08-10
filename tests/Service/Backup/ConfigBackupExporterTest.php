<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Integration\MailProviderConfig;
use App\Entity\Push\FcmConfig;
use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Infrastructure\Setup\RealProcessEnvironment;
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
     *
     * "Has a value for" means what {@see ConfigBackupEnvironment::current()}
     * means by it, and the expectation is derived from that rather than from a
     * list of names somebody believed the harness sets. It used to be such a
     * list — APP_SECRET, APP_ENCRYPTION_KEY and DATABASE_URL, asserted present
     * — and every name on it left the inventory except APP_ENCRYPTION_KEY,
     * which the docker test stack passes with `-e` and the GitHub runner does
     * not. So the suite was green on every developer machine and red on every
     * tag build from v0.0.19 to v0.0.23, saying "APP_ENCRYPTION_KEY is set in
     * this environment" about an environment where it was not set — `.env.test`
     * supplies it, and a backup deliberately does not read that far
     * ({@see ConfigBackupEnvironment::current()} states why).
     *
     * Which direction the environment happens to lean is therefore no longer
     * something this test has an opinion about.
     * {@see self::testAValueTravelsFromTheProcessEnvironmentAndNotFromDotenv()}
     * is where both directions are pinned, by setting and unsetting the
     * variable itself.
     */
    public function testTheEnvSectionIsExactlyTheInventoryMinusWhatIsUnset(): void
    {
        $inventory = static::getContainer()->get(ConfigBackupEnvironment::class);
        $document  = $this->exporter->document();

        /** @var array<string, string> $env */
        $env = $document['env'];

        self::assertSame([], array_diff(array_keys($env), $inventory->variables()), 'nothing outside the inventory');

        $expected = array_values(array_filter(
            $inventory->variables(),
            static fn (string $name): bool => null !== $inventory->current($name),
        ));

        self::assertSame(
            $expected,
            array_keys($env),
            'the file carries exactly the inventory variables this instance has a value for, in inventory order',
        );

        foreach ($env as $name => $value) {
            self::assertSame($inventory->current($name), $value, $name . ' was exported as something other than its current value');
            self::assertNotSame('', $value, $name . ' was exported as an empty string rather than omitted');
        }
    }

    /**
     * The same variable, both ways round, in one test — because the thing that
     * went wrong for five releases was a suite that could only see one of them.
     *
     * A value in the real process environment is carried. The very same name
     * supplied only by `.env.test` — which is still sitting in `$_SERVER`
     * throughout, and the test asserts that it is — is not, because a backup
     * reads the two levels that hold this installation's own decisions and not
     * the repository's shipped defaults.
     *
     * Against {@see RealProcessEnvironment} rather than a fake of it, so
     * `putenv()` is answered through the same `getenv()` the production service
     * uses; and against a generated-secrets path that does not exist, so the
     * second level is empty by construction and the assertion cannot be turned
     * green or red by whatever an earlier test wrote into `var/secrets`.
     */
    public function testAValueTravelsFromTheProcessEnvironmentAndNotFromDotenv(): void
    {
        // Nothing reads it into being: export() only ever reads the file, and
        // GeneratedSecretsFile::read() answers an absent one with no values.
        $environment = new ConfigBackupEnvironment(
            new GeneratedSecretsFile(sys_get_temp_dir() . '/plmail-no-such-secrets-' . bin2hex(random_bytes(8)) . '.env'),
            new RealProcessEnvironment(),
        );

        // Deliberately not the value in .env.test: if the two matched, the
        // first leg would pass on either source and prove nothing.
        $key = 'Xy3n8QpL0sVt7RdM2cWfB5hJ4kZaE6uN1gTyIoPqAsD=';

        $this->withProcessEnvironment(['APP_ENCRYPTION_KEY' => $key], function () use ($environment, $key): void {
            self::assertSame(
                $key,
                $environment->export()['APP_ENCRYPTION_KEY'] ?? null,
                'a value in the real process environment belongs in the file',
            );
        });

        $this->withProcessEnvironment(['APP_ENCRYPTION_KEY' => null], function () use ($environment): void {
            // The premise of the second leg, asserted rather than assumed: the
            // dotenv value really is present, and really is being passed over.
            self::assertNotSame(
                '',
                trim((string) ($_SERVER['APP_ENCRYPTION_KEY'] ?? '')),
                '.env.test still supplies APP_ENCRYPTION_KEY through $_SERVER',
            );

            self::assertArrayNotHasKey(
                'APP_ENCRYPTION_KEY',
                $environment->export(),
                'a value only .env supplies is a shipped default, not this installation\'s, and stays out of the file',
            );
        });
    }

    /**
     * The sweep for the trap's siblings.
     *
     * Every name the exporter refuses, set in the real process environment at
     * once — which is not a hypothetical arrangement: the E2E workflow sets
     * DATABASE_URL, MESSENGER_TRANSPORT_DSN and MAILER_DSN, the docker test
     * stack sets those plus APP_SECRET, and a compose deployment sets several
     * more. None of them may reach the file for being set, and the inventory
     * around them must not shift either.
     */
    public function testNamesTheEnvironmentSetsButTheInventoryDoesNotCarryStayOut(): void
    {
        $refused = [
            'APP_SECRET'              => 'a-source-machine-secret',
            'POSTGRES_PASSWORD'       => 'a-source-machine-password',
            'TRUSTED_PROXIES'         => '10.0.0.0/8',
            'DATABASE_URL'            => 'postgresql://app:app@elsewhere:5432/app',
            'MAILER_DSN'              => 'smtp://relay.source.test',
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default',
            'MERCURE_JWT_SECRET'      => 'a-source-machine-hub-secret',
        ];

        $inventory = static::getContainer()->get(ConfigBackupEnvironment::class);

        /** @var array<string, string> $undisturbed */
        $undisturbed = $this->exporter->document()['env'];
        $before      = array_keys($undisturbed);

        $this->withProcessEnvironment($refused, function () use ($inventory, $refused, $before): void {
            /** @var array<string, string> $env */
            $env = $this->exporter->document()['env'];

            foreach (array_keys($refused) as $name) {
                self::assertNotContains($name, $inventory->variables(), $name . ' is refused and must not be in the inventory');
                self::assertArrayNotHasKey($name, $env, $name . ' is set in this environment and must still not be exported');
            }

            self::assertSame($before, array_keys($env), 'setting a refused name changed nothing else about the file');
        });
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

    // ── The environment ───────────────────────────────────────────────────────

    /**
     * Run $body with the real process environment bent to $values — a null
     * value meaning "unset this one" — and put it back afterwards, whatever
     * $body did.
     *
     * `putenv()` and not `$_SERVER`, because the two are the distinction under
     * test: `$_SERVER` is where Dotenv's values already are, and writing there
     * would assert nothing about the level a backup actually reads. The restore
     * is in a finally for the obvious reason and one less obvious one — every
     * later test in the process inherits whatever this leaves behind, and a
     * suite that quietly changed its own environment halfway through is the
     * shape of bug this whole test exists to have caught.
     *
     * @param array<string, string|null> $values
     * @param callable(): void           $body
     */
    private function withProcessEnvironment(array $values, callable $body): void
    {
        $original = [];

        foreach (array_keys($values) as $name) {
            $current         = getenv($name);
            $original[$name] = false === $current ? null : $current;
        }

        try {
            foreach ($values as $name => $value) {
                $this->putEnvironment($name, $value);
            }

            $body();
        } finally {
            foreach ($original as $name => $value) {
                $this->putEnvironment($name, $value);
            }
        }
    }

    private function putEnvironment(string $name, ?string $value): void
    {
        // putenv() with a bare name is the only way to remove one; with `NAME=`
        // it would be set-and-empty, which ProcessEnvironment reads as absent
        // but the shell does not, and the two must not drift apart here.
        putenv(null === $value ? $name : $name . '=' . $value);
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
