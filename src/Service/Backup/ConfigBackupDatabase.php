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

    public function __construct(
        private FcmConfigRepository                 $fcmConfigs,
        private MailProviderConfigRepository        $mailProviders,
        private IntegrationProviderConfigRepository $integrationProviders,
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
