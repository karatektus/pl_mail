<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Ai\PromptSlot;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Ai\AiSettings;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\Integration\MailProviderConfig;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Monitoring\LogSettings;
use App\Entity\User\User;
use App\Domain\Enum\Account\EmailAliasSource;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Monitoring\LogSettingsRepository;
use App\Repository\User\UserRepository;
use App\Service\Backup\ConfigBackupExporter;
use App\Service\Backup\ConfigBackupImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An audit harness, not a regression guard: seal a filled-in installation,
 * open it as a different one, and print field by field what came back.
 *
 * Everything happens inside one transaction that is rolled back.
 */
final class ConfigBackupRoundTripTest extends KernelTestCase
{
    private const string EMAIL = 'audit@round-trip.test';

    private const string PASSWORD = 'a long enough backup password';

    private const string PASSWORD_HASH = '$2y$13$0123456789012345678901uJ7yCgMbNoMYVUFsyDhV0EjJb9dRSkq';

    private const string MAILBOX_PASSWORD = 'the-imap-password-9f2a';

    private const string TOKEN_HASH = 'ff7c3f8f4e2b1a0d9c8b7a6958473625140312fedcba98765432100fedcba987';

    private const string AI_TOKEN = 'the-ollama-proxy-token-771c';

    private const string LIVE_PASSWORD_HASH = '$2y$13$LIVEhashLIVEhashLIVEhaOuUxDkQ4YlQzVy4kQdKmVQaSDBHm6VG';

    /**
     * What a round trip deliberately does NOT carry.
     *
     * This list used to be six times longer and every line on it was a defect —
     * signatures, insight choices, OAuth scopes, the whole assistant
     * configuration. Those are fixed; what is left is the set of things a
     * backup is right to drop, each with the reason.
     *
     * Pinned as an exact set, so it can only change on purpose. A field that
     * stops travelling fails here, which is the alarm. Fixing one of these
     * fails here too, which is the prompt to delete its line — and adding a
     * deliberate exclusion means writing down why, next to the others.
     *
     * @var list<string>
     */
    private const array KNOWN_LOSSES = [
        // A filename in a storage volume the envelope does not carry, the same
        // decision the avatar gets. Restoring the name of a file that is not
        // there would be worse than restoring nothing: the settings page would
        // offer a signature that renders as a broken image.
        'user.signature (file)',
        // A read marker, like admin.logs_seen_at beside it. It records when
        // this person last waved the insight strip away, not what they chose —
        // and the choice, insights.pane_disabled, does travel.
        'setting insights.pane_dismissed_at',
    ];
    private const string OTHER_KEY = 'YW5vdGhlciBpbnN0YWxsYXRpb25zIGtleSwgMzIgYnk=';

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private ConfigBackupExporter $exporter;

    private ConfigBackupImporter $importer;

    private Encryptor $originalEncryptor;

    /** @var array<string, array{expected: mixed, actual: mixed}> */
    private array $report = [];

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
        $this->emptyTheInstall();
    }

    protected function tearDown(): void
    {
        EncryptedStringType::setEncryptor($this->originalEncryptor);

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheWholeRoundTripIntoAFreshInstall(): void
    {
        $aliasId = $this->seedTheInstall();

        $sealed = $this->exporter->export(self::PASSWORD);

        // ── The artefact is opaque ────────────────────────────────────────
        self::assertStringNotContainsString(self::PASSWORD, $sealed);
        self::assertStringNotContainsString(self::MAILBOX_PASSWORD, $sealed);
        self::assertStringNotContainsString(self::AI_TOKEN, $sealed);
        self::assertStringNotContainsString(self::EMAIL, $sealed);
        self::assertStringNotContainsString('imap.audit.test', $sealed);

        $this->becomeADifferentInstallation();

        $document = $this->importer->open($sealed, self::PASSWORD);
        $this->importer->apply($document);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $container = static::getContainer();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user, 'the user did not come back at all');

        $accounts = $container->get(AccountRepository::class)->findForUserOrdered($user);
        self::assertCount(1, $accounts);
        $account = $accounts[0];

        $restoredAlias = $account->aliases->first();
        self::assertInstanceOf(EmailAlias::class, $restoredAlias);

        // ── The user row ──────────────────────────────────────────────────
        $this->check('user.password (hash)', self::PASSWORD_HASH, $user->password);
        $this->check('user.locale', 'de', $user->locale);
        $this->check('user.timezone', 'Europe/Berlin', $user->timezone);
        $this->check('user.appearance.accent', '#123456', $user->appearance->accent);
        $this->check('user.appearance.fontScale', 1.125, $user->appearance->fontScale);
        $this->check('user.appearance.previewLines', 2, $user->appearance->previewLines);
        $this->check('user.aiPreferences.aboutMe', 'Ich baue Fahrräder.', $user->aiPreferences->aboutMe);
        $this->check('user.signature (file)', 'anna-signature.png', $user->signature);

        // ── The user settings bag ─────────────────────────────────────────
        $this->check('setting display.clock', '24h', $user->getSetting(User::SETTING_CLOCK));
        $this->check('setting search.sort', 'oldest', $user->getSetting(User::SETTING_SEARCH_SORT));
        $this->check('setting calendar.pane_width', 420, $user->getSetting(User::SETTING_CALENDAR_PANE_WIDTH));
        $this->check('setting sidebar.collapsed_sections', ['section:labels'], $user->getSetting(User::SETTING_SIDEBAR_COLLAPSED));
        $this->check('setting onboarding.completed_at', '2026-02-01T00:00:00+00:00', $user->getSetting(User::SETTING_ONBOARDING_COMPLETED_AT));
        $this->check('setting admin.collapsed_panels', ['health'], $user->getSetting(User::SETTING_ADMIN_COLLAPSED_PANELS));
        $this->check('setting insights.disabled_extractors', ['parcel'], $user->getSetting(User::SETTING_INSIGHTS_DISABLED));
        $this->check('setting insights.pane_disabled', true, $user->getSetting(User::SETTING_INSIGHT_PANE_DISABLED));
        $this->check('setting insights.pane_dismissed_at', '2026-08-01T00:00:00+00:00', $user->getSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT));
        $this->check('setting compose.forward_quote_collapsed', false, $user->getSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED));
        $this->check('setting compose.send_feedback', User::SEND_FEEDBACK_HOLD, $user->getSetting(User::SETTING_COMPOSE_SEND_FEEDBACK));
        $this->check('setting appearance.preview_width', 480, $user->getSetting(User::SETTING_APPEARANCE_PREVIEW_WIDTH));

        // ── The account ───────────────────────────────────────────────────
        $this->check('account.password (IMAP)', self::MAILBOX_PASSWORD, $account->password);
        $this->check('account.imapHost', 'imap.audit.test', $account->imapHost);
        $this->check('account.colorIndex', 3, $account->colorIndex);
        $this->check('account.oauthGrantedScopes', 'https://mail.google.com/ openid', $account->oauthGrantedScopes);
        $this->check('account setting sync.backfill_target', 0, $account->getSetting(Account::SETTING_BACKFILL_TARGET));
        $this->check('account setting compose.signature', '<p>Viele Grüße, Anna</p>', $account->getSetting(Account::SETTING_SIGNATURE));
        $this->check('account setting compose.read_receipt.default', 'ask', $account->getSetting(Account::SETTING_READ_RECEIPT_DEFAULT));
        $this->check(
            'account setting compose.signature.alias.N',
            '<p>Anna, privat</p>',
            $account->getSetting(Account::signatureAliasSetting((int) $restoredAlias->id)),
        );
        $this->check(
            'account setting compose.read_receipt.alias.N',
            'always',
            $account->getSetting(Account::readReceiptAliasSetting((int) $restoredAlias->id)),
        );

        // ── The app password ──────────────────────────────────────────────
        $tokens = $container->get(\App\Repository\User\ApiTokenRepository::class)->findForUser($user);
        $this->check('appPassword count', 1, count($tokens));
        $this->check('appPassword.tokenHash', self::TOKEN_HASH, $tokens[0]->tokenHash ?? null);
        $this->check('appPassword.hint', 'ab12cd', $tokens[0]->hint ?? null);
        $this->check(
            'appPassword.createdAt (exported by ConfigBackupUsers)',
            '2026-03-01T10:00:00+00:00',
            $tokens[0]->createdAt?->format(\DateTimeInterface::ATOM),
        );

        // ── Instance-level rows ───────────────────────────────────────────
        $mailProvider = $this->connection->fetchAssociative(
            'SELECT client_id FROM mail_provider_config WHERE provider = ?',
            [MailProvider::Google->value],
        );
        $this->check('mailProviderConfig.clientId', 'a-google-client-id', $mailProvider['client_id'] ?? null);

        $integrationProvider = $this->connection->fetchAssociative(
            'SELECT base_url FROM integration_provider_config WHERE provider = ?',
            [Provider::Nextcloud->value],
        );
        $this->check('integrationProviderConfig.baseUrl', 'https://cloud.audit.test', $integrationProvider['base_url'] ?? null);

        $ai = $container->get(AiSettingsRepository::class)->current();
        $this->check('aiSettings row exists', true, $ai instanceof AiSettings);
        $this->check('aiSettings.baseUrl', 'http://10.0.0.5:11434', $ai?->baseUrl);
        $this->check('aiSettings.apiToken', self::AI_TOKEN, $ai?->apiToken);
        $this->check('aiSettings.chatModel', 'llama3.1:8b', $ai?->chatModel);
        $this->check('aiSettings.embeddingModel', 'nomic-embed-text', $ai?->embeddingModel);
        $this->check('aiSettings.embeddingDimensions', 768, $ai?->embeddingDimensions);
        $this->check('aiSettings.isEnabled', true, $ai?->isEnabled);
        $this->check('aiSettings.summaryEnabled', true, $ai?->summaryEnabled);
        $this->check('aiSettings.prompts.summary', 'Fasse dich kurz.', $ai?->prompts->of(PromptSlot::Summary));

        $log = $container->get(LogSettingsRepository::class)->current();
        $this->check('logSettings.minimumLevel', 'debug', $log?->minimumLevel);

        // Every name in KNOWN_LOSSES is a defect this audit found, not a rule.
        // Pinned so that the set can only shrink: a new field that stops
        // travelling fails here, and fixing one of these fails here too, which
        // is the prompt to delete its line.
        self::assertSame(
            self::KNOWN_LOSSES,
            array_keys($this->losses()),
            'the set of fields lost in a config-backup round trip has changed',
        );
    }


    /**
     * The same sealed file opened on an install that already holds that
     * address, with different values on every row it touches.
     *
     * Two opposite policies meet here and both are deliberate. A person's row
     * is skipped whole — February's password must not silently replace today's,
     * and half a file grafted onto a live user is a shape neither install ever
     * had. The operator's own instance-level rows are overwritten, because
     * "put my configuration back" is the errand, and a restore that kept the
     * live values would do nothing at all.
     */
    public function testRestoringOverAnExistingUser(): void
    {
        $this->seedTheInstall();

        $sealed = $this->exporter->export(self::PASSWORD);

        // The live install now disagrees with the file about everything.
        $container = static::getContainer();
        $user      = $container->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);

        $user->nameFirst = 'Somebody Else';
        $user->password  = self::LIVE_PASSWORD_HASH;
        $user->appearance->accent = '#abcdef';
        $user->setSetting(User::SETTING_CLOCK, '12h');

        $liveAccounts = $container->get(AccountRepository::class)->findForUserOrdered($user);
        self::assertCount(1, $liveAccounts);
        $liveAccounts[0]->password = 'a-live-imap-password';

        $ai = $container->get(AiSettingsRepository::class)->current();
        self::assertInstanceOf(AiSettings::class, $ai);
        $ai->baseUrl = 'http://live-host:11434';

        $log = $container->get(LogSettingsRepository::class)->current();
        self::assertInstanceOf(LogSettings::class, $log);
        $log->minimumLevel = 'error';

        $this->entityManager->flush();
        $this->entityManager->clear();

        $plan = $this->importer->apply($this->importer->open($sealed, self::PASSWORD));

        $this->entityManager->flush();
        $this->entityManager->clear();

        $container = static::getContainer();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);

        // ── The person is left exactly as they are ────────────────────────
        $this->check('COLLISION user.nameFirst (live kept?)', 'Somebody Else', $user->nameFirst);
        $this->check('COLLISION user.password (live kept?)', self::LIVE_PASSWORD_HASH, $user->password);
        $this->check('COLLISION user.appearance.accent (live kept?)', '#abcdef', $user->appearance->accent);
        $this->check('COLLISION user.setting display.clock (live kept?)', '12h', $user->getSetting(User::SETTING_CLOCK));

        $accounts = $container->get(AccountRepository::class)->findForUserOrdered($user);
        $account  = $accounts[0] ?? null;
        self::assertInstanceOf(Account::class, $account);

        $this->check('COLLISION account.password (live kept?)', 'a-live-imap-password', $account->password);
        $this->check('COLLISION account count (no graft?)', 1, count($accounts));
        $this->check('COLLISION user count (no duplicate?)', 1, $container->get(UserRepository::class)->countAll());

        // ── Instance-level rows have the OPPOSITE policy, and the file wins ─
        //
        // Worth stating because it has a sharp edge: a stale file will replace
        // a working model host with one that has moved. That is the same edge
        // the Firebase key and the provider registrations have always had, and
        // the review page names every row before anything is written.
        $ai = $container->get(AiSettingsRepository::class)->current();
        $this->check('COLLISION aiSettings.baseUrl (file wins?)', 'http://10.0.0.5:11434', $ai?->baseUrl);

        $log = $container->get(LogSettingsRepository::class)->current();
        $this->check('COLLISION logSettings.minimumLevel (file wins?)', 'debug', $log?->minimumLevel);

        $this->check('COLLISION plan keptUsers', 1, count($plan->keptUsers()));
        $this->check('COLLISION plan restoredUsers', 0, count($plan->restoredUsers()));

        self::assertSame(
            [],
            array_keys($this->losses()),
            'restoring over an existing install did not follow the two documented policies',
        );
    }

    /**
     * Record one field, so the whole picture can be asserted at once.
     *
     * Collected rather than asserted line by line, because the useful failure
     * here is the SET of fields that stopped travelling — one assertion naming
     * six lost settings is a defect report, six separate failures on the first
     * of them is a puzzle.
     */
    private function check(string $field, mixed $expected, mixed $actual): void
    {
        $this->report[$field] = ['expected' => $expected, 'actual' => $actual];
    }

    /** @return array<string, array{expected: mixed, actual: mixed}> */
    private function losses(): array
    {
        return array_filter($this->report, static fn (array $row): bool => $row['expected'] !== $row['actual']);
    }

    /** @return int the id of the seeded alias */
    private function seedTheInstall(): int
    {
        $user = new User();

        $user->email     = self::EMAIL;
        $user->nameFirst = 'Anna';
        $user->nameLast  = 'Beispiel';
        $user->password  = self::PASSWORD_HASH;
        $user->roles     = [User::ROLE_ADMIN];
        $user->locale    = 'de';
        $user->timezone  = 'Europe/Berlin';
        $user->signature = 'anna-signature.png';

        $user->appearance->accent       = '#123456';
        $user->appearance->fontScale    = 1.125;
        $user->appearance->previewLines = 2;

        $user->aiPreferences->aboutMe = 'Ich baue Fahrräder.';

        $user->setSetting(User::SETTING_CLOCK, '24h');
        $user->setSetting(User::SETTING_SEARCH_SORT, 'oldest');
        $user->setSetting(User::SETTING_CALENDAR_PANE_WIDTH, 420);
        $user->setSetting(User::SETTING_SIDEBAR_COLLAPSED, ['section:labels']);
        $user->setSetting(User::SETTING_ONBOARDING_COMPLETED_AT, '2026-02-01T00:00:00+00:00');
        $user->setSetting(User::SETTING_ADMIN_COLLAPSED_PANELS, ['health']);
        $user->setSetting(User::SETTING_INSIGHTS_DISABLED, ['parcel']);
        $user->setSetting(User::SETTING_INSIGHT_PANE_DISABLED, true);
        $user->setSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT, '2026-08-01T00:00:00+00:00');
        $user->setSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED, false);
        $user->setSetting(User::SETTING_COMPOSE_SEND_FEEDBACK, User::SEND_FEEDBACK_HOLD);
        $user->setSetting(User::SETTING_APPEARANCE_PREVIEW_WIDTH, 480);

        $this->entityManager->persist($user);

        $account = new Account();

        $account->usr                = $user;
        $account->name               = 'Work';
        $account->email              = 'anna@work.audit.test';
        $account->username           = 'anna@work.audit.test';
        $account->password           = self::MAILBOX_PASSWORD;
        $account->imapHost           = 'imap.audit.test';
        $account->imapPort           = 993;
        $account->smtpHost           = 'smtp.audit.test';
        $account->smtpPort           = 587;
        $account->authType           = 'password';
        $account->isActive           = true;
        $account->isPrimary          = true;
        $account->colorIndex         = 3;
        $account->oauthGrantedScopes = 'https://mail.google.com/ openid';
        $account->setSetting(Account::SETTING_BACKFILL_TARGET, 0);
        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Viele Grüße, Anna</p>');
        $account->setSetting(Account::SETTING_READ_RECEIPT_DEFAULT, 'ask');

        $this->entityManager->persist($account);

        $token = \App\Entity\User\ApiToken::restore($user, 'iPhone', self::TOKEN_HASH, 'ab12cd', null, null);
        $this->entityManager->persist($token);

        $alias = new EmailAlias($account, 'anna+privat@work.audit.test', EmailAliasSource::Manual);
        $account->aliases->add($alias);
        $this->entityManager->persist($alias);

        $gmail = new MailProviderConfig(MailProvider::Google);
        $gmail->clientId     = 'a-google-client-id';
        $gmail->clientSecret = 'a-google-client-secret';
        $this->entityManager->persist($gmail);

        $nextcloud = new IntegrationProviderConfig(Provider::Nextcloud);
        $nextcloud->isEnabled = true;
        $nextcloud->baseUrl   = 'https://cloud.audit.test';
        $nextcloud->clientId  = 'a-nextcloud-client-id';
        $this->entityManager->persist($nextcloud);

        $ai = new AiSettings();
        $ai->isEnabled           = true;
        $ai->baseUrl             = 'http://10.0.0.5:11434';
        $ai->apiToken            = self::AI_TOKEN;
        $ai->chatModel           = 'llama3.1:8b';
        $ai->embeddingModel      = 'nomic-embed-text';
        $ai->embeddingDimensions = 768;
        $ai->searchEnabled       = true;
        $ai->summaryEnabled      = true;
        $ai->prompts->put(PromptSlot::Summary, 'Fasse dich kurz.');
        $this->entityManager->persist($ai);

        $log = new LogSettings();
        $log->minimumLevel = 'debug';
        $this->entityManager->persist($log);

        $this->entityManager->flush();

        // A creation date the file carries and the restore has to reproduce.
        $this->connection->executeStatement(
            "UPDATE api_token SET created_at = '2026-03-01T10:00:00+00:00' WHERE id = ?",
            [$token->id],
        );

        $aliasId = (int) $alias->id;

        // The per-alias keys can only be written once the alias has an id.
        $account->setSetting(Account::signatureAliasSetting($aliasId), '<p>Anna, privat</p>');
        $account->setSetting(Account::readReceiptAliasSetting($aliasId), 'always');

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $aliasId;
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
}
