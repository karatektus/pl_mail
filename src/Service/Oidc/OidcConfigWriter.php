<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Entity\Oidc\OidcConfig;
use App\Repository\Oidc\OidcConfigRepository;
use App\Service\Integration\ProviderConfigWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Saving what Admin → SSO submitted, with the write-only-secret rule applied
 * by the one method that already owns it.
 *
 * A service rather than four lines in the controller for the reason
 * FcmConfigWriter gives: the part that can go quietly wrong is the secret, and
 * an administrator changing the button's label must not lose it in the process.
 */
final readonly class OidcConfigWriter
{
    public function __construct(
        private OidcConfigRepository   $configs,
        private ProviderConfigWriter   $secrets,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The row an administrator is editing, created on first sight so the form
     * always has something to bind to.
     */
    public function current(): OidcConfig
    {
        return $this->configs->current() ?? new OidcConfig();
    }

    public function save(OidcConfig $config, FormInterface $form): void
    {
        $this->secrets->applySecret($form, static fn (?string $secret) => $config->clientSecret = $secret, $config->clientSecret);

        // An empty box is "not set", stored as null — one state rather than
        // two, so isComplete() and the environment fallback have one thing to
        // ask.
        $config->issuer      = $this->secrets->nullIfBlank($config->issuer);
        $config->clientId    = $this->secrets->nullIfBlank($config->clientId);
        $config->buttonLabel = $this->secrets->nullIfBlank($config->buttonLabel);
        $config->scopes      = $this->secrets->nullIfBlank($config->scopes);

        if (null === $config->id) {
            $this->entityManager->persist($config);
        }

        $this->entityManager->flush();
    }
}
