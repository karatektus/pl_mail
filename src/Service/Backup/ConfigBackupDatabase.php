<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\Integration\MailProviderConfig;
use App\Entity\Push\FcmConfig;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\MailProviderConfigRepository;
use App\Repository\Push\FcmConfigRepository;
use App\Domain\Ai\KeepAlive;
use App\Entity\Ai\AiSettings;
use App\Entity\Monitoring\LogSettings;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Monitoring\LogSettingsRepository;
use App\Domain\Enum\Ai\PromptSlot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The three tables that hold admin-configured settings, read out in the clear
 * and written back under whatever key is in force here.
 *
 * **Decrypted on the way out, and that is the entire point.** Every credential
 * in these tables is stored through EncryptedStringType, which means the bytes
 * in the column can only be read with the APP_ENCRYPTION_KEY that wrote them. A
 * backup carrying those bytes would be carrying noise to any install that is
 * not the one it came from — which is every install a backup is ever restored
 * onto. So the values leave here as plaintext, inside an envelope the admin's
 * password seals, and go back in through the ORM, which re-encrypts them with
 * the target's key on the way to the column. The envelope is the protection;
 * the column encryption is a different one, for a different threat, and
 * stacking them would have produced a file that is safe and useless.
 *
 * **Keyed by provider, not listed.** `mailProviders` and
 * `integrationProviders` are objects whose keys are the enum's backing values,
 * so a restore looks each one up rather than trusting array order, and a
 * provider the target build does not know is skipped by name instead of by
 * position. That is what lets a backup from a newer plMail — one with a
 * provider this build has never heard of — restore the rest of itself instead
 * of failing.
 *
 * **Absent means absent.** A provider with no row is not exported, and a
 * provider not in the document is not touched on import. An import is a restore
 * of what the backup holds, never a mirror: deleting rows the file happens not
 * to mention would make "restore my Firebase key" a way to lose every
 * integration.
 */
final readonly class ConfigBackupDatabase
{
    public const string FCM_CONFIG = 'fcmConfig';

    public const string MAIL_PROVIDERS = 'mailProviders';

    public const string INTEGRATION_PROVIDERS = 'integrationProviders';

    /**
     * The assistant configuration — Admin → AI.
     *
     * Added late, and the gap it closes was the largest in the feature. The
     * per-user half of the AI settings (AiPreferences) had been carried since
     * the day it shipped; the instance-level half it depends on had not. A
     * restore therefore brought back everyone's assistant preferences pointing
     * at a model host that no longer existed, with the reverse-proxy token —
     * typed once and held in plaintext nowhere else — gone for good, and every
     * prompt an administrator had rewritten silently back to the shipped text.
     */
    public const string AI_SETTINGS = 'aiSettings';

    /** The chosen log level — Admin → Logs. */
    public const string LOG_SETTINGS = 'logSettings';

    /**
     * Every key the section carries.
     *
     * Exists so {@see \App\Tests\Service\Backup\ConfigBackupCompletenessTest}
     * can compare this list against the tables an administrator actually
     * configures, rather than against itself.
     *
     * @var list<string>
     */
    public const array SECTION_KEYS = [
        self::FCM_CONFIG,
        self::MAIL_PROVIDERS,
        self::INTEGRATION_PROVIDERS,
        self::AI_SETTINGS,
        self::LOG_SETTINGS,
    ];

    public function __construct(
        private FcmConfigRepository                 $fcmConfigs,
        private MailProviderConfigRepository        $mailProviders,
        private IntegrationProviderConfigRepository $integrationProviders,
        private AiSettingsRepository                $aiSettings,
        private LogSettingsRepository               $logSettings,
        private EntityManagerInterface              $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return [
            self::FCM_CONFIG            => $this->exportFcmConfig(),
            self::MAIL_PROVIDERS        => $this->exportMailProviders(),
            self::INTEGRATION_PROVIDERS => $this->exportIntegrationProviders(),
            self::AI_SETTINGS           => $this->exportAiSettings(),
            self::LOG_SETTINGS          => $this->exportLogSettings(),
        ];
    }

    /**
     * The same shape the export produces, read back from the live rows, so the
     * review can compare two arrays rather than an array against an entity
     * graph.
     *
     * One method for both directions rather than a comparer per table: "is this
     * row already what the backup says" is one question, and answering it three
     * times in three shapes is how a review comes to claim a change it will not
     * make.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return $this->export();
    }

    /**
     * @param array<string, mixed> $fcm
     */
    public function restoreFcmConfig(array $fcm): void
    {
        $config = $this->fcmConfigs->current() ?? new FcmConfig();

        $config->restore(
            $this->string($fcm, 'serviceAccountJson'),
            $this->string($fcm, 'projectId'),
            $this->string($fcm, 'applicationId'),
            $this->string($fcm, 'apiKey'),
            $this->string($fcm, 'senderId'),
            $this->string($fcm, 'androidPackage'),
            true === ($fcm['isEnabled'] ?? false),
        );

        if (null === $config->id) {
            $this->entityManager->persist($config);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public function restoreMailProvider(MailProvider $provider, array $values): void
    {
        $config = $this->mailProviders->findOneByProvider($provider);

        if (null === $config) {
            $config = new MailProviderConfig($provider);
            $this->entityManager->persist($config);
        }

        $config->clientId              = $this->string($values, 'clientId');
        $config->clientSecret          = $this->string($values, 'clientSecret');
        $config->pushVerificationToken = $this->string($values, 'pushVerificationToken');
        $config->settings              = $this->settings($values);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function restoreIntegrationProvider(Provider $provider, array $values): void
    {
        $config = $this->integrationProviders->findOneByProvider($provider);

        if (null === $config) {
            $config = new IntegrationProviderConfig($provider);
            $this->entityManager->persist($config);
        }

        $config->isEnabled    = true === ($values['isEnabled'] ?? false);
        $config->baseUrl      = $this->string($values, 'baseUrl');
        $config->clientId     = $this->string($values, 'clientId');
        $config->clientSecret = $this->string($values, 'clientSecret');
        $config->settings     = $this->settings($values);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    /**
     * The assistant configuration, or null when nobody has set one up.
     *
     * Null rather than an empty shape, and it matters here more than elsewhere:
     * a row that does not exist and a row switched off are different states,
     * and AiSettings treats "off with nothing configured" as legitimately
     * silent. Exporting a hollow row would restore an install into the second
     * state while its operator believed the first.
     *
     * `apiToken` is read through the entity so the decrypting hook has run —
     * the same reason exportFcmConfig() reads serviceAccountJson that way, and
     * the same consequence: it is plaintext inside the sealed envelope, which
     * is what makes a restore onto a host with a different APP_ENCRYPTION_KEY
     * work at all.
     *
     * @return array<string, mixed>|null
     */
    private function exportAiSettings(): ?array
    {
        $settings = $this->aiSettings->current();

        if (null === $settings) {
            return null;
        }

        $prompts = [];

        // Only the slots somebody has actually rewritten. An untouched slot is
        // absent rather than null, so a restore leaves the shipped wording in
        // place instead of pinning today's default into the file forever — the
        // wording changes between releases, and a backup should not freeze it.
        foreach (PromptSlot::cases() as $slot) {
            $text = $settings->prompts->of($slot);

            if (null !== $text && '' !== $text) {
                $prompts[$slot->value] = $text;
            }
        }

        return [
            'isEnabled'             => $settings->isEnabled,
            'baseUrl'               => $settings->baseUrl,
            'apiToken'              => $settings->apiToken,
            'chatModel'             => $settings->chatModel,
            'chatKeepAlive'         => $settings->chatKeepAlive,
            'embeddingModel'        => $settings->embeddingModel,
            'embeddingKeepAlive'    => $settings->embeddingKeepAlive,
            'embeddingDimensions'   => $settings->embeddingDimensions,
            'searchEnabled'         => $settings->searchEnabled,
            'categorisationEnabled' => $settings->categorisationEnabled,
            'writingHelpEnabled'    => $settings->writingHelpEnabled,
            'summaryEnabled'        => $settings->summaryEnabled,
            'prompts'               => $prompts,
        ];
    }

    /**
     * The chosen log level, or null when the install is following the
     * environment.
     *
     * Null is exported as null and never as the resolved env value. Writing the
     * effective level would turn an install that follows APP_DB_LOG_LEVEL into
     * one that has been pinned — quietly, and on the next restore rather than
     * on the one that made the mistake.
     *
     * @return array<string, mixed>|null
     */
    private function exportLogSettings(): ?array
    {
        $settings = $this->logSettings->current();

        if (null === $settings) {
            return null;
        }

        return ['minimumLevel' => $settings->minimumLevel];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function restoreAiSettings(array $values): void
    {
        $settings = $this->aiSettings->current() ?? new AiSettings();

        $settings->isEnabled             = true === ($values['isEnabled'] ?? false);
        $settings->baseUrl               = $this->string($values, 'baseUrl');
        $settings->apiToken              = $this->string($values, 'apiToken');
        $settings->chatModel             = $this->string($values, 'chatModel');
        $settings->embeddingModel        = $this->string($values, 'embeddingModel');
        // Through KeepAlive::normalised() rather than string() alone, because
        // this is the one writer that is not the form. A file can carry
        // whitespace where the form's constraint would have refused it, and a
        // column holding "  " shows an administrator a value they cannot save
        // again. Absent from an older backup means null, which is the
        // host's-own-default state and the right answer for a file written
        // before this setting existed.
        $settings->chatKeepAlive         = KeepAlive::normalised($this->string($values, 'chatKeepAlive'));
        $settings->embeddingKeepAlive    = KeepAlive::normalised($this->string($values, 'embeddingKeepAlive'));
        $settings->embeddingDimensions   = is_int($values['embeddingDimensions'] ?? null) ? $values['embeddingDimensions'] : null;
        $settings->searchEnabled         = true === ($values['searchEnabled'] ?? false);
        $settings->categorisationEnabled = true === ($values['categorisationEnabled'] ?? false);
        $settings->writingHelpEnabled    = true === ($values['writingHelpEnabled'] ?? false);
        $settings->summaryEnabled        = true === ($values['summaryEnabled'] ?? false);

        $prompts = $values['prompts'] ?? [];

        // Every slot is written, including the ones the file does not mention,
        // and that is deliberate: put() with null clears an override. A restore
        // has to be able to say "this install had no custom summary prompt",
        // which a loop over only the present keys could never express.
        foreach (PromptSlot::cases() as $slot) {
            $text = is_array($prompts) ? ($prompts[$slot->value] ?? null) : null;

            $settings->prompts->put($slot, is_string($text) && '' !== $text ? $text : null);
        }

        if (null === $settings->id) {
            $this->entityManager->persist($settings);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public function restoreLogSettings(array $values): void
    {
        $settings = $this->logSettings->currentOrNew();

        $settings->minimumLevel = $this->string($values, 'minimumLevel');

        if (null === $settings->id) {
            $this->entityManager->persist($settings);
        }
    }

    private function exportFcmConfig(): ?array
    {
        $config = $this->fcmConfigs->current();

        if (null === $config) {
            return null;
        }

        return [
            'isEnabled' => $config->isEnabled,
            // Read through the entity, so the property hook that decrypts it
            // has run. This is the value that would otherwise be dead weight in
            // the file — see the class docblock.
            'serviceAccountJson' => $config->serviceAccountJson,
            'projectId'          => $config->projectId,
            // clientEmail is derived from the key on restore and is exported
            // only so a human reading a decrypted document can tell two
            // backups apart without parsing a 2 kB blob.
            'clientEmail'    => $config->clientEmail,
            'applicationId'  => $config->applicationId,
            'apiKey'         => $config->apiKey,
            'senderId'       => $config->senderId,
            'androidPackage' => $config->androidPackage,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function exportMailProviders(): array
    {
        $exported = [];

        foreach ($this->mailProviders->findAllIndexedByProvider() as $key => $config) {
            $exported[$key] = [
                'clientId'              => $config->clientId,
                'clientSecret'          => $config->clientSecret,
                'pushVerificationToken' => $config->pushVerificationToken,
                'settings'              => $config->settings,
            ];
        }

        return $exported;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function exportIntegrationProviders(): array
    {
        $exported = [];

        foreach ($this->integrationProviders->findAllIndexedByProvider() as $key => $config) {
            $exported[$key] = [
                'isEnabled'    => $config->isEnabled,
                'baseUrl'      => $config->baseUrl,
                'clientId'     => $config->clientId,
                'clientSecret' => $config->clientSecret,
                'settings'     => $config->settings,
            ];
        }

        return $exported;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function string(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function settings(array $values): array
    {
        $settings = $values['settings'] ?? null;

        return is_array($settings) ? $settings : [];
    }
}
