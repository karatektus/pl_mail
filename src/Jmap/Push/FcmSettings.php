<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Domain\DTO\Push\ClientConfig;
use App\Domain\DTO\Push\ServiceAccount;
use App\Domain\Exception\InvalidFirebaseCredentialsException;
use App\Entity\Push\FcmConfig;
use App\Repository\Push\FcmConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * "Is FCM usable right now, with which credentials, and what does a client need
 * to be told?" — asked by the Session capability, by PushSubscription/set before
 * it accepts a token, and by FcmSender before every send.
 *
 * One class so those three cannot disagree. They did not have to: each could
 * have read the row and applied its own test, and the install where the Session
 * advertises `fcm: true` while every create is refused is the one that would
 * have produced.
 *
 * **A malformed stored key reads as unconfigured, loudly.** The only way one
 * gets in is an encryption key that changed under it or a hand-edited row, and
 * both mean the credential is gone rather than wrong. Throwing here would break
 * the Session — which every client fetches before anything else — over a
 * feature that install is not using, so it logs an error and answers no.
 *
 * Memoised per instance and therefore per request: the dispatcher asks once per
 * subscription and a user with four devices should not cost four queries. The
 * container is rebuilt per request under FrankenPHP's runtime, so an admin
 * saving new credentials is in force on the next one.
 */
final class FcmSettings
{
    private bool $resolved = false;

    private ?FcmConfig $config = null;

    private ?ServiceAccount $account = null;

    public function __construct(
        private readonly FcmConfigRepository $configs,
        private readonly LoggerInterface     $logger,
    ) {}

    /**
     * Configured — both halves — and turned on. This is what the Session
     * publishes as `fcm`, and what gates the whole feature.
     */
    public function isActive(): bool
    {
        return null !== $this->serviceAccount();
    }

    /**
     * The credentials to sign a grant with, or null when FCM is off, half set
     * up or unreadable — the three cases a caller has to treat identically.
     */
    public function serviceAccount(): ?ServiceAccount
    {
        $this->resolve();

        return $this->account;
    }

    /**
     * What an Android client passes to FirebaseOptions.Builder, or null when
     * FCM is not active.
     *
     * Rebuilt from the stored columns rather than from a kept copy of
     * google-services.json: the file is large, mostly irrelevant, and the four
     * values are the contract. A client that could see the whole file would be
     * reading fields plMail has made no promise about.
     */
    public function clientConfig(): ?ClientConfig
    {
        $this->resolve();

        if (null === $this->account || null === $this->config) {
            return null;
        }

        return new ClientConfig(
            projectId:     (string) $this->config->projectId,
            applicationId: (string) $this->config->applicationId,
            apiKey:        (string) $this->config->apiKey,
            senderId:      (string) $this->config->senderId,
            packageName:   (string) $this->config->androidPackage,
        );
    }

    private function resolve(): void
    {
        if (true === $this->resolved) {
            return;
        }

        $this->resolved = true;

        $config = $this->configs->current();

        if (null === $config || false === $config->isActive()) {
            return;
        }

        try {
            $this->account = ServiceAccount::fromJson((string) $config->serviceAccountJson);
            $this->config  = $config;
        } catch (InvalidFirebaseCredentialsException $exception) {
            $this->logger->error('FCM: the stored service-account key cannot be read; push over FCM is off.', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
