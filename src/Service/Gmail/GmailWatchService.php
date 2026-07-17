<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Account;
use App\Entity\Mailbox;
use App\Service\Mail\GmailApiClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers or renews a Gmail push-notification watch for a mailbox.
 *
 * Google watch registrations expire after at most 7 days (604 800 seconds).
 * The GmailWatchRenewalCommand calls this daily for any watch expiring within
 * the next 24 hours.
 *
 * Prerequisites in Google Cloud:
 *   1. A Pub/Sub topic:  projects/{project}/topics/gmail-push
 *   2. The topic must grant gmail-api@system.gserviceaccount.com the
 *      "Pub/Sub Publisher" role.
 *   3. A push subscription pointing at https://your-domain.com/gmail/push
 */
final class GmailWatchService
{
    public function __construct(
        private readonly GmailApiClient         $apiClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        private readonly string                 $pubSubTopicName,
    ) {}

    /**
     * Register (or renew) a watch for the mailbox's inbox.
     * Idempotent — calling it on an already-watched mailbox simply resets the
     * expiry window.
     */
    public function watch(Account $account): void
    {

        $this->logger->info('GmailWatchService: registering watch', [
            'accountId' => $account->getId(),
            'account'   => $account->getEmail(),
        ]);

        $response = $this->apiClient->watch($account, $this->pubSubTopicName);

        // expiration is a Unix timestamp in *milliseconds*
        $expirationMs = (int) ($response['expiration'] ?? 0);
        $expiry = $expirationMs > 0
            ? new DateTimeImmutable()->setTimestamp((int) ($expirationMs / 1000))
            : (new DateTimeImmutable('+7 days'));

        $historyId    = (string) ($response['historyId'] ?? '');
        $resourceName = (string) ($response['resourceName'] ?? '');

        $account->setGmailWatchExpiry($expiry);
        $account->setGmailWatchResourceName($resourceName);

        // Seed the historyId if this is the first watch registration
        if (null === $account->getGmailHistoryId() && '' !== $historyId) {
            $account->setGmailHistoryId($historyId);
        }

        $this->em->flush();

        $this->logger->info('GmailWatchService: watch registered', [
            'accountId'    => $account->getId(),
            'expiry'       => $expiry->format('Y-m-d H:i:s'),
            'resourceName' => $resourceName,
        ]);
    }

    /**
     * Stop the active watch for a mailbox (e.g. when an account is removed).
     */
    public function stopWatch(Account $account): void
    {

        try {
            $this->apiClient->stopWatch($account);
        } catch (\Throwable $e) {
            // Non-fatal — the watch will expire naturally
            $this->logger->warning('GmailWatchService: stopWatch failed', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);
        }

        $account->setGmailWatchExpiry(null);
        $account->setGmailWatchResourceName(null);
        $this->em->flush();
    }
}
