<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\Integration\MailProviderConfig;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Saving an OAuth app registration, with the write-only-secret rule applied
 * consistently.
 *
 * The rule is the whole reason this is a service rather than a few lines in a
 * controller: the form never renders a stored secret, so an empty submission
 * has to mean "keep it" and not "clear it". Get that wrong in one of the two
 * places that save a provider and an admin editing a tenant wipes the client
 * secret without being told.
 *
 * Extracted from IntegrationProviderController so the setup wizard saves
 * exactly what the admin panel saves.
 */
final readonly class ProviderConfigWriter
{
    /**
     * Integration providers whose app registration is the *same* registration
     * as a mail provider's — one Google Cloud project covers Gmail, Drive and
     * Photos, and one Entra app covers Outlook and OneDrive. Copying the
     * credentials across is what saves an admin doing the whole dance twice.
     */
    public const array INHERITABLE = [
        'googleDrive'  => 'google',
        'googlePhotos' => 'google',
        'oneDrive'     => 'microsoft',
    ];

    public function __construct(
        private IntegrationProviderConfigRepository $configs,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Copy a mail provider's client credentials onto every integration
     * provider that shares its app registration.
     *
     * @return list<Provider> the providers that now have credentials
     */
    public function inheritFromMailProvider(MailProviderConfig $source): array
    {
        if (false === $source->isComplete()) {
            return [];
        }

        $inherited = [];

        foreach (self::INHERITABLE as $providerValue => $mailValue) {
            if ($mailValue !== $source->provider->value) {
                continue;
            }

            $provider = Provider::from($providerValue);
            $config   = $this->configs->findOneByProvider($provider);

            if (null === $config) {
                $config = new IntegrationProviderConfig($provider);
                $this->entityManager->persist($config);
            }

            $config->clientId     = $source->clientId;
            $config->clientSecret = $source->clientSecret;
            $config->isEnabled    = true;

            $inherited[] = $provider;
        }

        $this->entityManager->flush();

        return $inherited;
    }

    /**
     * A mail provider's registration: the client credentials, plus the fields
     * that live in its settings bag because only one provider has them.
     */
    public function saveMailProvider(MailProviderConfig $config, FormInterface $form): void
    {
        if (null === $config->id) {
            $this->entityManager->persist($config);
        }

        $this->applySecret($form, static fn (?string $secret) => $config->clientSecret = $secret, $config->clientSecret);

        if (true === $form->has('tenant')) {
            $config->setTenant($form->get('tenant')->getData());
        }

        if (true === $form->has('pubsubTopic')) {
            $config->setPubsubTopic($form->get('pubsubTopic')->getData());
        }

        // Write-only, like every other secret here.
        if (true === $form->has('pushVerificationToken')) {
            $submittedToken = $this->nullIfBlank($form->get('pushVerificationToken')->getData());

            if (null !== $submittedToken) {
                $config->pushVerificationToken = $submittedToken;
            }
        }

        $this->entityManager->flush();
    }

    public function saveIntegrationProvider(IntegrationProviderConfig $config, FormInterface $form): void
    {
        if (null === $config->id) {
            $this->entityManager->persist($config);
        }

        $this->applySecret($form, static fn (?string $secret) => $config->clientSecret = $secret, $config->clientSecret);

        $this->entityManager->flush();
    }

    /**
     * Blank means "leave it alone"; the clear checkbox is the only way to
     * remove a secret, and it is only offered when there is one to remove.
     *
     * @param callable(?string): void $assign
     */
    public function applySecret(FormInterface $form, callable $assign, ?string $current): void
    {
        // Absent entirely on app-password providers, which have no app
        // registration to hold — Nextcloud's form is a toggle and an address.
        if (false === $form->has('clientSecret')) {
            return;
        }

        if (true === $form->has('clearClientSecret') && true === $form->get('clearClientSecret')->getData()) {
            $assign(null);

            return;
        }

        $submitted = $this->nullIfBlank($form->get('clientSecret')->getData());

        if (null !== $submitted) {
            $assign($submitted);

            return;
        }

        $assign($current);
    }

    public function nullIfBlank(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
