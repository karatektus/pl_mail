<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\ConfigBackupController;
use App\Entity\User\User;
use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Service\Backup\ConfigBackupDatabase;
use App\Service\Backup\ConfigBackupExporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The four doors on the config backup page.
 *
 * This is the one endpoint in plMail that hands out every credential the
 * installation owns in a single response, and the one that writes them back.
 * Three things therefore have to be true of it and are asserted rather than
 * reasoned about: an ordinary user cannot reach it, a request without a CSRF
 * token cannot drive it, and the review step really does write nothing.
 *
 * That last one is the interesting assertion. A reviewer reading the controller
 * sees import() call plan() and apply() call apply(), and it looks obvious — but
 * the failure mode is one refactor away and completely silent, because a review
 * that had already applied its changes would render exactly the same page.
 *
 * Everything runs inside a transaction that is rolled back, so the suite's
 * shared database is left as it was found. Files are deliberately not part of
 * any fixture here: an apply that wrote a PEM into var/secrets would escape the
 * transaction, and what a file write does is ConfigBackupImporterTest's subject
 * anyway.
 */
final class ConfigBackupControllerTest extends WebTestCase
{
    private const string PASSWORD = 'a properly long backup password';

    private EntityManagerInterface $em;

    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnOrdinaryUserCanNeitherSeeTheBackupPageNorExportFromIt(): void
    {
        $client = $this->boot();
        $client->loginUser($this->seedUser());

        $client->request('GET', '/admin/config-backup');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/config-backup/export', ['_token' => 'irrelevant', 'password' => self::PASSWORD, 'password_repeat' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(403, 'a non-admin was handed the installation\'s credentials');

        $client->request('POST', '/admin/config-backup/import', ['_token' => 'irrelevant', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/config-backup/apply', ['_token' => 'irrelevant', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A signed-in admin is not enough. Without this, any page an admin visits
     * could make their browser fetch the whole credential set and post it away.
     */
    public function testATokenlessPostIsRefusedOnEveryAction(): void
    {
        $client = $this->signInAsAdmin();

        foreach (['export', 'import', 'apply'] as $action) {
            $client->request('POST', '/admin/config-backup/' . $action, [
                '_token'          => 'nonsense',
                'password'        => self::PASSWORD,
                'password_repeat' => self::PASSWORD,
            ]);

            self::assertResponseStatusCodeSame(403, $action . ' ran without a CSRF token');
        }
    }

    public function testAnAdminGetsADownloadThatOpensWithThePasswordTheyTyped(): void
    {
        $client = $this->signInAsAdmin();

        $client->request('POST', '/admin/config-backup/export', [
            '_token'          => $this->token($client, 'config_backup_export'),
            'password'        => self::PASSWORD,
            'password_repeat' => self::PASSWORD,
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertStringContainsString(
            'attachment; filename=plmail-config-',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        // A proxy holding this in a shared cache would be the whole feature
        // undone.
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $document = static::getContainer()->get(ConfigBackupCipher::class)
            ->open((string) $client->getResponse()->getContent(), self::PASSWORD);

        self::assertSame(ConfigBackupCipher::FORMAT, $document['format']);
        self::assertArrayHasKey('env', $document);
        self::assertArrayHasKey('database', $document);
    }

    public function testAMistypedRepeatExportsNothing(): void
    {
        $client = $this->signInAsAdmin();

        $client->request('POST', '/admin/config-backup/export', [
            '_token'          => $this->token($client, 'config_backup_export'),
            'password'        => self::PASSWORD,
            'password_repeat' => self::PASSWORD . '!',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            'error=' . ConfigBackupController::ERROR_PASSWORD_MISMATCH,
            (string) $client->getResponse()->headers->get('Location'),
            'the admin was not told why no file arrived',
        );
    }

    /**
     * The review renders the plan and leaves the database exactly as it was.
     */
    public function testReviewingABackupWritesNothing(): void
    {
        $client = $this->signInAsAdmin();
        $this->connection->executeStatement('TRUNCATE TABLE fcm_config CASCADE');

        $client->request(
            'POST',
            '/admin/config-backup/import',
            ['_token' => $this->token($client, 'config_backup_import'), 'password' => self::PASSWORD],
            ['backup' => $this->upload($this->sealedFixture())],
        );

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(ConfigBackupDatabase::FCM_CONFIG, $body, 'the review does not say what it would do');
        // Every environment value has to appear under the manual heading, with
        // its line. This one is the check that the page is honest.
        self::assertStringContainsString('APP_SECRET=a-restored-secret', $body);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM fcm_config'), 'the review applied itself');
    }

    /**
     * Driven through the form the review rendered rather than by posting to
     * /apply directly, and that is not politeness — it is the only way the
     * second step is reachable. The apply token is minted while rendering a
     * review, so a test that forged one would be exercising a path no browser
     * can take.
     */
    public function testApplyingWritesTheDatabaseHalfAndNothingElse(): void
    {
        $client = $this->signInAsAdmin();
        $this->connection->executeStatement('TRUNCATE TABLE fcm_config CASCADE');

        $review = $client->request(
            'POST',
            '/admin/config-backup/import',
            ['_token' => $this->token($client, 'config_backup_import'), 'password' => self::PASSWORD],
            ['backup' => $this->upload($this->sealedFixture())],
        );

        self::assertResponseIsSuccessful();

        $client->submit($review->filter('#config-backup-apply')->form(['password' => self::PASSWORD]));

        self::assertResponseIsSuccessful();

        self::assertSame(
            'plmail-controller-test',
            (string) $this->connection->fetchOne('SELECT project_id FROM fcm_config LIMIT 1'),
            'the credentials the review promised were not written',
        );

        // And what it wrote is readable through the ORM, which is the whole
        // point of re-encrypting rather than copying: the column now holds
        // ciphertext this instance's key produced.
        self::assertStringStartsWith(
            'enc:v1:',
            (string) $this->connection->fetchOne('SELECT service_account_json FROM fcm_config LIMIT 1'),
        );
    }

    public function testAWrongPasswordSaysSoAndChangesNothing(): void
    {
        $client = $this->signInAsAdmin();
        $this->connection->executeStatement('TRUNCATE TABLE fcm_config CASCADE');

        $client->request(
            'POST',
            '/admin/config-backup/import',
            ['_token' => $this->token($client, 'config_backup_import'), 'password' => 'not the password at all'],
            ['backup' => $this->upload($this->sealedFixture())],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'does not open this file',
            (string) $client->getResponse()->getContent(),
            'the admin was not told the password was wrong',
        );
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM fcm_config'));
    }

    public function testAFileThatIsNotABackupIsRefusedWithoutTouchingAnything(): void
    {
        $client = $this->signInAsAdmin();
        $this->connection->executeStatement('TRUNCATE TABLE fcm_config CASCADE');

        $client->request(
            'POST',
            '/admin/config-backup/import',
            ['_token' => $this->token($client, 'config_backup_import'), 'password' => self::PASSWORD],
            ['backup' => $this->upload('this is somebody\'s shopping list')],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('not a plMail configuration backup', (string) $client->getResponse()->getContent());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM fcm_config'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * A backup with one row's worth of database and one environment line, and
     * deliberately no `files` section — see the class docblock.
     */
    private function sealedFixture(): string
    {
        return static::getContainer()->get(ConfigBackupCipher::class)->seal([
            'format'     => ConfigBackupCipher::FORMAT,
            'version'    => ConfigBackupExporter::DOCUMENT_VERSION,
            'exportedAt' => '2026-01-01T00:00:00+00:00',
            'instance'   => 'https://elsewhere.plmail.test',
            'env'        => ['APP_SECRET' => 'a-restored-secret'],
            'files'      => [],
            'database'   => [
                ConfigBackupDatabase::FCM_CONFIG => [
                    'isEnabled'          => true,
                    'serviceAccountJson' => '{"type":"service_account","project_id":"plmail-controller-test",'
                        . '"client_email":"p@plmail-controller-test.iam.gserviceaccount.com",'
                        . '"private_key":"-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n"}',
                    'projectId'      => 'plmail-controller-test',
                    'clientEmail'    => 'p@plmail-controller-test.iam.gserviceaccount.com',
                    'applicationId'  => '1:1:android:abc',
                    'apiKey'         => 'AIza-key',
                    'senderId'       => '1',
                    'androidPackage' => 'test.plmail',
                ],
            ],
        ], self::PASSWORD);
    }

    /**
     * An upload PHP never moved anywhere. `test: true` is what lets the
     * framework accept a file that did not arrive through a real multipart
     * request; the bytes still travel exactly as they would.
     */
    private function upload(string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'plmail-backup-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'plmail-config-2026-01-01.backup', 'application/octet-stream', null, true);
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
        $user            = new User();
        $user->email     = 'config-backup-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Fixture';
        $user->nameLast  = 'Person';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A real CSRF token for $id.
     *
     * The GET first is load-bearing — same trick, and the same reason,
     * AdminDataResetTest records.
     */
    private function token(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/admin/config-backup');

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
