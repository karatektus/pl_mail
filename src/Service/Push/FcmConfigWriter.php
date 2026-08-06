<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\Push\FcmConfig;
use App\Repository\Push\FcmConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Saving the Firebase configuration, with the write-only-key rule applied in
 * one place.
 *
 * The same reasoning ProviderConfigWriter states for OAuth secrets: the form
 * never renders the stored key, so an empty submission means "keep it". Written
 * as a service rather than four lines in the controller because the rule is the
 * part that can go quietly wrong — an admin toggling FCM off and on again must
 * not lose the credential in the process, and that is not visible from the
 * controller action, which looks like it is only saving a checkbox.
 *
 * Order matters and is the reason clearing is handled first: a submission that
 * both clears and pastes is a person replacing a key, and applying the clear
 * afterwards would discard what they just typed.
 */
final readonly class FcmConfigWriter
{
    public function __construct(
        private FcmConfigRepository    $configs,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The row an admin is editing, created on first sight so the form always
     * has something to bind to.
     */
    public function current(): FcmConfig
    {
        return $this->configs->current() ?? new FcmConfig();
    }

    public function save(FcmConfig $config, FormInterface $form): void
    {
        $key = trim((string) $form->get('serviceAccountJson')->getData());
        $app = trim((string) $form->get('googleServicesJson')->getData());

        if ($form->has('clearServiceAccount') && true === $form->get('clearServiceAccount')->getData()) {
            // Also turns the feature off — see FcmConfig::forgetServiceAccount.
            $config->forgetServiceAccount();
        }

        // Both halves in one call, because the project-match rule is about the
        // pair and cannot be stated by two separate assignments. Already proved
        // by the form's POST_SUBMIT listener; this would throw otherwise, which
        // is the correct outcome for a caller that skipped validation.
        $config->useCredentials('' === $key ? null : $key, '' === $app ? null : $app);

        if (null === $config->id) {
            $this->entityManager->persist($config);
        }

        $this->entityManager->flush();
    }
}
