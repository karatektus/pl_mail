<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Backup\ConfigBackupChange;
use App\Domain\Enum\Backup\ConfigBackupDisposition;
use App\Domain\Enum\Backup\ConfigBackupSection;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\DTO\Backup\ConfigBackupPlan;
use App\Domain\DTO\Backup\ConfigBackupPlanItem;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarShareLink;
use App\Entity\Integration\Integration;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Rule\MailRule;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Calendar\CalendarShareLinkRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Rule\MailRuleRepository;
use App\Repository\User\ApiTokenRepository;
use App\Repository\User\UserRepository;
use App\Service\Backup\ConfigBackupExporter;
use App\Service\Backup\ConfigBackupImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A backup that knows its own operator: export a person and everything they set
 * up, open it as a different installation, and get them back able to sign in.
 *
 * **The claim under test is not "the fields round-trip".** It is that the
 * restored install *works*: the password the person already knows still opens
 * the account, their authenticator app still produces accepted codes, their
 * phone's app password still authenticates, and their mailbox still connects —
 * on a host whose APP_ENCRYPTION_KEY is not the one any of that was written
 * under. Everything encrypted at rest has to come out from under one key and go
 * back in under another, and the only way to know it did is to read it back
 * after the swap and then prove the old key cannot.
 *
 * The swap goes through EncryptedStringType's static seam, the one
 * Kernel::boot() uses and the one ConfigBackupImporterTest documents — as close
 * to "a different machine" as a single process gets. The source rows are
 * truncated between export and import for the plainer reason that `email` is
 * unique: an install cannot restore a person it still has, which is the policy
 * this file also tests.
 *
 * Everything happens inside one transaction that is rolled back, so the shared
 * test database is left as it was found.
 */
final class ConfigBackupUsersTest extends KernelTestCase
{
    private const string EMAIL = 'anna@backup-users.test';

    /** A bcrypt hash. Not generated here: what travels is the stored string. */
    private const string PASSWORD_HASH = '$2y$13$0123456789012345678901uJ7yCgMbNoMYVUFsyDhV0EjJb9dRSkq';

    private const string TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    private const string MAILBOX_PASSWORD = 'the-imap-password';

    private const string INTEGRATION_SECRET = 'a-nextcloud-app-password';

    private const string TOKEN_HASH = 'ff7c3f8f4e2b1a0d9c8b7a6958473625140312fedcba98765432100fedcba987';

    private const string SHARE_DIGEST = 'aa11bb22cc33dd44ee55ff6677889900aabbccddeeff00112233445566778899';

    /** A second install's key: 32 bytes, base64, and not the test stack's. */
    private const string OTHER_KEY = 'YW5vdGhlciBpbnN0YWxsYXRpb25zIGtleSwgMzIgYnk=';

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private ConfigBackupExporter $exporter;

    private ConfigBackupImporter $importer;

    private Encryptor $originalEncryptor;

    protected function setUp(): void
    {
        self::bootKernel();

        $container               = static::getContainer();
        $this->entityManager     = $container->get(EntityManagerInterface::class);
        $this->exporter          = $container->get(ConfigBackupExporter::class);
        $this->importer          = $container->get(ConfigBackupImporter::class);
        $this->originalEncryptor = $container->get(Encryptor::class);
        $this->connection        = $this->entityManager->getConnection();

        $this->connection->beginTransaction();

        // The seeded users would otherwise all be in the export, which would
        // make every assertion here depend on a fixture this test does not own.
        $this->emptyTheInstall();
    }

    protected function tearDown(): void
    {
        // Before the rollback: a static left holding the other key would make
        // every suite after this one in the same process read the database
        // with the wrong one.
        EncryptedStringType::setEncryptor($this->originalEncryptor);

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The headline: one person, everything they configured, and a different
     * encryption key on the other side.
     */
    public function testAUserAndEverythingTheyConfiguredSurviveARestoreUnderANewKey(): void
    {
        $this->seedTheOperator();

        $document = $this->exporter->document();

        self::assertArrayHasKey(self::EMAIL, $document['users'], 'the export does not carry the user at all');

        // Everything past this line is the other install: a different key, and
        // nobody at home.
        $this->becomeADifferentInstallation();

        $plan = $this->importer->apply($document);

        self::assertSame(
            ConfigBackupDisposition::Applied,
            $this->itemFor($plan, ConfigBackupSection::Users, self::EMAIL)->disposition,
        );

        $this->entityManager->flush();
        $this->entityManager->clear();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);

        self::assertInstanceOf(User::class, $user, 'the user was not created');

        // ── Sign-in ───────────────────────────────────────────────────────
        // The hash byte for byte: anything else and the password the person
        // knows stops working, which is the one failure that cannot be worked
        // around from inside the app.
        self::assertSame(self::PASSWORD_HASH, $user->password);
        self::assertSame('Anna', $user->nameFirst);
        self::assertSame([User::ROLE_ADMIN], $user->roles);
        self::assertSame('de', $user->locale);
        self::assertSame('Europe/Berlin', $user->timezone);

        // ── The second factor ─────────────────────────────────────────────
        // Read back through the entity, so this is the value the new install's
        // key decrypts to — and it is the string the authenticator app was
        // enrolled with, or every code it produces is refused.
        self::assertSame(self::TOTP_SECRET, $user->totpSecret);
        self::assertTrue($user->isTotpAuthenticationEnabled(), '2FA came back switched off');
        self::assertSame('2026-02-03T04:05:06+00:00', $user->totpConfirmedAt?->format(DATE_ATOM));
        self::assertTrue($user->isBackupCode('recovery-code-one'), 'the recovery codes did not survive');

        // ── The app password ──────────────────────────────────────────────
        $tokens = static::getContainer()->get(ApiTokenRepository::class)->findForUser($user);

        self::assertCount(1, $tokens);
        self::assertSame(self::TOKEN_HASH, $tokens[0]->tokenHash, 'every JMAP client would have been signed out');
        self::assertSame('iPhone', $tokens[0]->name);

        // ── The mailbox, which is the credentialed thing beyond the user row ──
        $accounts = static::getContainer()->get(AccountRepository::class)->findForUserOrdered($user);

        self::assertCount(1, $accounts);
        self::assertSame(self::MAILBOX_PASSWORD, $accounts[0]->password, 'the IMAP password did not survive the key change');
        self::assertSame('imap.example.test', $accounts[0]->imapHost);
        self::assertSame(993, $accounts[0]->imapPort);
        self::assertCount(1, $accounts[0]->aliases);

        // And it really was re-encrypted rather than copied: the bytes in the
        // column are noise to the install that made the backup. This is the
        // assertion that makes the block above mean something — an importer
        // that wrote the ciphertext straight through would satisfy every line
        // before it.
        $stored = (string) $this->connection->fetchOne(
            'SELECT password FROM account WHERE id = ?',
            [$accounts[0]->id],
        );

        self::assertStringStartsWith('enc:v1:', $stored);

        // ── The integration, whose secret is the other encrypted credential ──
        $integrations = static::getContainer()->get(IntegrationRepository::class)->findForUserOrdered($user);

        self::assertCount(1, $integrations);
        self::assertSame(self::INTEGRATION_SECRET, $integrations[0]->secret);
        self::assertSame('https://cloud.example.test', $integrations[0]->baseUrl);

        // ── The published link ────────────────────────────────────────────
        // The digest and nothing else: the token was never stored, so carrying
        // the digest is the only way the URL somebody mailed round still works.
        $links = static::getContainer()->get(CalendarShareLinkRepository::class)->findForUser($user);

        self::assertCount(1, $links);
        self::assertSame(self::SHARE_DIGEST, $links[0]->tokenDigest);
        self::assertCount(1, $links[0]->calendars);

        self::expectExceptionMessageMatches('/APP_ENCRYPTION_KEY has changed/');
        $this->originalEncryptor->decrypt($stored);
    }

    /**
     * The ids inside a filter are the source database's, and a restore that
     * wrote one through would attach somebody's rule to a stranger's label.
     *
     * Asserted by comparing against the ids the restore actually minted, which
     * on a table with a sequence are never the ones the export carried.
     */
    public function testAFiltersLabelAndIntegrationReferencesArePointedAtTheNewRows(): void
    {
        $this->seedTheOperator();

        $document = $this->exporter->document();
        $sourceIds = $this->labelIdsIn($document);

        $this->becomeADifferentInstallation();
        $this->importer->apply($document);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);

        $labels = static::getContainer()->get(LabelRepository::class)->findForUser($user);
        $rules  = static::getContainer()->get(MailRuleRepository::class)->findForUserOrdered($user);

        self::assertCount(2, $labels);
        self::assertCount(1, $rules);

        $byName = [];

        foreach ($labels as $label) {
            $byName[(string) $label->name] = $label;
        }

        // The child kept its parent, through a two-pass wiring that does not
        // care what order the document listed them in.
        self::assertSame($byName['Archive'], $byName['Receipts']->parent);
        self::assertSame(LabelRole::Archive, $byName['Archive']->role);

        $receiptsId = $byName['Receipts']->id;

        self::assertNotContains($receiptsId, $sourceIds, 'the fixture is void unless the new ids differ');

        self::assertSame($receiptsId, $rules[0]->conditions['conditions'][1]['hasLabel'] ?? null);
        self::assertSame($receiptsId, $rules[0]->actions[0]['labelId'] ?? null);

        $integrations = static::getContainer()->get(IntegrationRepository::class)->findForUserOrdered($user);

        self::assertSame($integrations[0]->id, $rules[0]->actions[1]['integrationId'] ?? null);

        // The calendar's two references came through the same maps.
        $calendars = static::getContainer()->get(CalendarRepository::class)->findForUser($user);
        $accounts  = static::getContainer()->get(AccountRepository::class)->findForUserOrdered($user);

        self::assertCount(1, $calendars);
        self::assertSame($accounts[0], $calendars[0]->account);
        self::assertSame($integrations[0], $calendars[0]->integration);
    }

    /**
     * The policy the whole feature is designed around: a person this install
     * already has is left completely alone.
     *
     * The file carries a different password, a different second factor and an
     * extra mailbox for the same address. None of it lands. Overwriting is how
     * a three-month-old backup silently resets today's password, and it is not
     * recoverable from inside the app — the plaintext exists nowhere.
     */
    public function testAUserThisInstallAlreadyHasIsNeverOverwritten(): void
    {
        $this->seedTheOperator();

        $document = $this->exporter->document();

        // The file now disagrees with the live install about everything that
        // matters, exactly as a stale backup would.
        $document['users'][self::EMAIL]['password']              = '$2y$13$aDifferentHashEntirely012345678901234567890123456789012';
        $document['users'][self::EMAIL]['twoFactor']['secret']   = 'ZZZZZZZZZZZZZZZZ';
        $document['users'][self::EMAIL]['nameFirst']             = 'Somebody Else';
        $document['users'][self::EMAIL]['accounts'][0]['password'] = 'a-stale-imap-password';

        $plan = $this->importer->apply($document);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $item = $this->itemFor($plan, ConfigBackupSection::Users, self::EMAIL);

        self::assertSame(ConfigBackupDisposition::KeptDeliberately, $item->disposition);
        self::assertSame(ConfigBackupChange::Differs, $item->change, 'the review has to say the file disagrees');
        self::assertSame([$item], $plan->keptUsers());
        self::assertSame([], $plan->restoredUsers());

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);

        self::assertInstanceOf(User::class, $user);
        self::assertSame(self::PASSWORD_HASH, $user->password, 'the live password was replaced from a file');
        self::assertSame(self::TOTP_SECRET, $user->totpSecret, 'the live second factor was replaced from a file');
        self::assertSame('Anna', $user->nameFirst);

        // And the subtree went with them, whole: no second mailbox grafted on.
        self::assertCount(1, static::getContainer()->get(AccountRepository::class)->findForUserOrdered($user));
        self::assertSame(
            self::MAILBOX_PASSWORD,
            static::getContainer()->get(AccountRepository::class)->findForUserOrdered($user)[0]->password,
        );

        self::assertSame(1, static::getContainer()->get(UserRepository::class)->countAll(), 'a duplicate was created');
    }

    /**
     * A user matching the file by email but soft deleted is still "already
     * here": `deletedAt` is somebody's decision, and the unique index would
     * refuse the insert regardless.
     */
    public function testASoftDeletedUserBlocksTheRestoreRatherThanBeingResurrected(): void
    {
        $this->seedTheOperator();

        $document = $this->exporter->document();

        $this->connection->executeStatement('UPDATE "user" SET deleted_at = now() WHERE email = ?', [self::EMAIL]);
        $this->entityManager->clear();

        $plan = $this->importer->apply($document);
        $this->entityManager->clear();

        $item = $this->itemFor($plan, ConfigBackupSection::Users, self::EMAIL);

        self::assertSame(ConfigBackupDisposition::KeptDeliberately, $item->disposition);
        self::assertSame(ConfigBackupChange::Differs, $item->change);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT count(*) FROM "user" WHERE email = ?', [self::EMAIL]),
            'the deleted user was resurrected or duplicated',
        );
        self::assertNotNull(
            $this->connection->fetchOne('SELECT deleted_at FROM "user" WHERE email = ?', [self::EMAIL]),
            'the deletion was undone',
        );
    }

    /**
     * A document from before users were part of the format restores exactly as
     * it did then.
     *
     * Version 1 and no `users` key at all — not an empty one, absent — because
     * that is what the files an operator is holding today actually look like.
     * The importer reads a missing section as an empty one, so this passing is
     * the whole of the back-compatibility claim.
     */
    public function testAVersionOneBackupWithNoUsersSectionStillImports(): void
    {
        $plan = $this->importer->plan($this->importer->open(
            static::getContainer()->get(\App\Infrastructure\Backup\ConfigBackupCipher::class)->seal([
                'format'   => \App\Infrastructure\Backup\ConfigBackupCipher::FORMAT,
                'version'  => 1,
                'env'      => ['APP_SECRET' => 'a-restored-app-secret'],
                'database' => [],
            ], 'a long enough password'),
            'a long enough password',
        ));

        self::assertSame([], array_values(array_filter(
            $plan->items,
            static fn (ConfigBackupPlanItem $item): bool => ConfigBackupSection::Users === $item->section,
        )), 'a document with no users section produced user items');

        self::assertNotSame([], $plan->items, 'the rest of the document stopped being planned');
        self::assertSame([], $plan->keptUsers());
        self::assertSame([], $plan->restoredUsers());
    }

    /**
     * A document from a NEWER plMail is refused whole rather than restored
     * without its users — the check that makes bumping the version safe in the
     * other direction.
     */
    public function testADocumentFromANewerFormatIsRefused(): void
    {
        $this->expectException(\App\Domain\Exception\ConfigBackupException::class);

        $this->importer->open(
            static::getContainer()->get(\App\Infrastructure\Backup\ConfigBackupCipher::class)->seal([
                'format'  => \App\Infrastructure\Backup\ConfigBackupCipher::FORMAT,
                'version' => ConfigBackupExporter::DOCUMENT_VERSION + 1,
            ], 'a long enough password'),
            'a long enough password',
        );
    }

    /**
     * The export carries no mail, and no per-browser grant.
     *
     * Stated as an assertion because it is the boundary the whole feature is
     * judged on, and because it is the kind of thing that erodes: somebody
     * needing "just the trusted devices" one day would be adding a way to skip
     * a second factor by restoring a file.
     */
    public function testTheExportCarriesConfigurationAndNotDataOrDeviceGrants(): void
    {
        $this->seedTheOperator();

        /** @var array<string, array<string, mixed>> $users */
        $users = $this->exporter->document()['users'];
        $anna  = $users[self::EMAIL];

        foreach (['trustedDevices', 'pushSubscriptions', 'messages', 'threads', 'contacts', 'events', 'avatar'] as $absent) {
            self::assertArrayNotHasKey($absent, $anna, $absent . ' has no business in a config backup');
        }

        // The sync state of a mailbox belongs to the host that did the syncing.
        foreach (['lastSyncedAt', 'gmailHistoryId', 'graphDeltaLinks', 'graphSubscriptionId'] as $absent) {
            self::assertArrayNotHasKey($absent, $anna['accounts'][0], $absent . ' is the other host\'s state');
        }

        // A calendar's push channel names the OLD instance's webhook URL.
        foreach (['pushSecret', 'pushChannelId', 'syncToken'] as $absent) {
            self::assertArrayNotHasKey($absent, $anna['calendars'][0], $absent . ' is a live registration elsewhere');
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * One administrator with a filled-in installation behind them: 2FA, an app
     * password, a mailbox with an alias, an integration, a label tree, a filter
     * pointing at both, a calendar mirroring the mailbox through the
     * integration, and a published share link.
     *
     * Deliberately one of everything that holds a credential or a reference,
     * because those are the two things a restore can get wrong.
     */
    private function seedTheOperator(): void
    {
        $user = new User();

        $user->email     = self::EMAIL;
        $user->nameFirst = 'Anna';
        $user->nameLast  = 'Beispiel';
        $user->password  = self::PASSWORD_HASH;
        $user->roles     = [User::ROLE_ADMIN];
        $user->locale    = 'de';
        $user->timezone  = 'Europe/Berlin';

        $user->restoreTwoFactor(self::TOTP_SECRET, new \DateTimeImmutable('2026-02-03T04:05:06+00:00'));
        $user->backupCodes = [User::hashBackupCode('recovery-code-one'), User::hashBackupCode('recovery-code-two')];
        $user->setSetting(User::SETTING_ONBOARDING_COMPLETED_AT, '2026-02-01T00:00:00+00:00');

        $this->entityManager->persist($user);

        $this->entityManager->persist(ApiToken::restore($user, 'iPhone', self::TOKEN_HASH, 'ab12cd', null, null));

        $account = new Account();

        $account->usr            = $user;
        $account->name           = 'Work';
        $account->email          = 'anna@work.example.test';
        $account->username       = 'anna@work.example.test';
        $account->password       = self::MAILBOX_PASSWORD;
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'smtp.example.test';
        $account->smtpPort       = 587;
        $account->authType       = 'password';
        $account->isActive       = true;
        $account->isPrimary      = true;
        $account->setSetting(Account::SETTING_SYNC_LIMIT, 500);

        $this->entityManager->persist($account);

        $alias = new EmailAlias($account, 'anna+lists@work.example.test', EmailAliasSource::Manual);
        $account->aliases->add($alias);
        $this->entityManager->persist($alias);

        $integration = new Integration($user, Provider::Nextcloud, 'Home cloud');

        $integration->baseUrl  = 'https://cloud.example.test';
        $integration->username = 'anna';
        $integration->secret   = self::INTEGRATION_SECRET;

        $this->entityManager->persist($integration);

        $archive = new Label();

        $archive->usr  = $user;
        $archive->name = 'Archive';
        $archive->role = LabelRole::Archive;

        $this->entityManager->persist($archive);

        $receipts = new Label();

        $receipts->usr    = $user;
        $receipts->name   = 'Receipts';
        $receipts->parent = $archive;
        $receipts->color  = '#ff8800';

        $this->entityManager->persist($receipts);

        $calendar = new Calendar();

        $calendar->usr         = $user;
        $calendar->account     = $account;
        $calendar->integration = $integration;
        $calendar->name        = 'Work';
        $calendar->role        = CalendarRole::Custom;
        $calendar->timeZone    = 'Europe/Berlin';
        // Sync state, which must NOT travel.
        $calendar->syncToken  = 'a-caldav-sync-token';
        $calendar->pushSecret = 'a-push-channel-secret';

        $this->entityManager->persist($calendar);

        $link = new CalendarShareLink();

        $link->usr         = $user;
        $link->name        = 'Team view';
        $link->tokenDigest = self::SHARE_DIGEST;
        $link->calendars->add($calendar);

        $this->entityManager->persist($link);

        // Flushed before the rule, because the rule's JSON has to carry the ids
        // the sequence actually gave these rows — that is the whole subject of
        // the remapping test.
        $this->entityManager->flush();

        $rule = new MailRule();

        $rule->usr        = $user;
        $rule->name       = 'File the receipts';
        $rule->account    = $account;
        $rule->conditions = [
            'operator'   => 'AND',
            'conditions' => [
                ['from' => 'billing@'],
                ['hasLabel' => $receipts->id],
            ],
        ];
        $rule->actions = [
            ['type' => 'applyLabel', 'labelId' => $receipts->id],
            ['type' => 'saveToIntegration', 'integrationId' => $integration->id],
            ['type' => 'markRead'],
        ];

        $this->entityManager->persist($rule);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<int>
     */
    private function labelIdsIn(array $document): array
    {
        /** @var array<string, array<string, mixed>> $users */
        $users = $document['users'];
        $ids   = [];

        /** @var list<array<string, mixed>> $labels */
        $labels = $users[self::EMAIL]['labels'];

        foreach ($labels as $label) {
            if (is_int($label['id'] ?? null)) {
                $ids[] = $label['id'];
            }
        }

        return $ids;
    }

    private function emptyTheInstall(): void
    {
        $tables = array_filter(
            $this->connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', implode(', ', $tables)));
        $this->entityManager->clear();
    }

    private function becomeADifferentInstallation(): void
    {
        EncryptedStringType::setEncryptor(new Encryptor(self::OTHER_KEY));

        $this->emptyTheInstall();
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
