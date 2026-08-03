<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Enum\Account\MailProvider;
use App\Repository\Integration\MailProviderConfigRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the Gmail Pub/Sub settings come from.
 *
 * Same rule as the OAuth credentials: a stored value wins, the environment is
 * the fallback. Introduced as a service rather than injecting the repository
 * into each consumer because there are four of them — the watch service, the
 * subscription manager, the webhook and the admin monitor — and they were each
 * reading the env var directly, which is exactly the duplication that made
 * these values impossible to change without a restart.
 */
final readonly class GmailPushSettings
{
    public function __construct(
        private MailProviderConfigRepository $configRepository,
        #[Autowire(env: 'GMAIL_PUBSUB_TOPIC')]
        private string $envTopic = '',
        #[Autowire(env: 'GMAIL_PUBSUB_VERIFICATION_TOKEN')]
        private string $envVerificationToken = '',
    ) {
    }

    /** The topic to watch, or null when neither source has one. */
    public function topic(): ?string
    {
        $stored = $this->configRepository->findOneByProvider(MailProvider::Google)?->pubsubTopic;

        if (null !== $stored && '' !== $stored) {
            return $stored;
        }

        return '' === $this->envTopic ? null : $this->envTopic;
    }

    /**
     * The shared secret the webhook checks, or null when none is configured —
     * in which case the caller must decide whether to accept unverified pushes,
     * which is not this class's decision to make.
     */
    public function verificationToken(): ?string
    {
        $stored = $this->configRepository->findOneByProvider(MailProvider::Google)?->pushVerificationToken;

        if (null !== $stored && '' !== $stored) {
            return $stored;
        }

        return '' === $this->envVerificationToken ? null : $this->envVerificationToken;
    }
}
