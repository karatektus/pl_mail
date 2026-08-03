<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Connecting a user to an app-password service.
 *
 * Extracted from IntegrationController so the setup wizard connects the same
 * way the settings page does. The part worth sharing is not the form handling
 * — it is what happens around it: blank means "keep the stored credential",
 * and every save re-probes the service.
 *
 * That probe is the point. A connection that stores cleanly but cannot reach
 * the server is worse than one that fails loudly, because the user only finds
 * out mid-compose. The result lands on the entity either way.
 */
final readonly class IntegrationConnector
{
    public function __construct(
        private IntegrationProviderConfigRepository $configs,
        private IntegrationDriverRegistry $drivers,
        private IntegrationUrlValidator $urlValidator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The admin's configuration for a provider a user may actually connect to.
     *
     * @throws NotFoundHttpException when the provider is off or unconfigured —
     *                               indistinguishable from one that does not
     *                               exist, which is the right answer to a
     *                               hand-typed URL
     */
    public function requireConnectable(Provider $provider): IntegrationProviderConfig
    {
        $config = $this->configs->findOneByProvider($provider);

        if (null === $config || false === $config->isConnectable()) {
            throw new NotFoundHttpException();
        }

        return $config;
    }

    /**
     * Whether the user gets to name the server themselves, or the admin has
     * pinned one for everybody.
     */
    public function isUrlEditable(Provider $provider, ?IntegrationProviderConfig $config): bool
    {
        return $this->urlValidator->isUserEditable($provider, $config);
    }

    /**
     * Save a submitted connection and try it.
     *
     * @return string|null the failure message, or null when the service answered
     */
    public function save(Integration $integration, FormInterface $form): ?string
    {
        if (null === $integration->id) {
            $this->entityManager->persist($integration);
        }

        // Blank keeps the stored credential: the field never renders it, so
        // blank cannot mean "clear it".
        $secret = $form->has('secret') ? trim((string) $form->get('secret')->getData()) : '';

        if ('' !== $secret) {
            $integration->secret = $secret;
        }

        $error = $this->probe($integration);

        $this->entityManager->flush();

        return $error;
    }

    /**
     * Try an existing connection again, for when a service was down or a
     * credential was rotated at the other end.
     *
     * @return string|null the failure message, or null when the service answered
     */
    public function retest(Integration $integration): ?string
    {
        $error = $this->probe($integration);

        $this->entityManager->flush();

        return $error;
    }

    /**
     * Ask the service whether the credentials work, recording the outcome on
     * the entity either way. Returns the failure message, or null on success.
     */
    private function probe(Integration $integration): ?string
    {
        try {
            $this->drivers->forIntegration($integration)->verify($integration);
            $integration->recordSuccess();

            return null;
        } catch (IntegrationException $e) {
            $integration->recordFailure($e->getMessage());

            return $e->getMessage();
        }
    }
}
