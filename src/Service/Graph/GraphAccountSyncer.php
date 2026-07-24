<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Account;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Microsoft Graph sync entry point. Label-based architecture, identical in
 * shape to GmailAccountSyncer: sync the folder list first so every
 * parentFolderId on an incoming message resolves, then plan message work
 * directly on the account. Graph accounts have no Mailbox rows.
 */
final readonly class GraphAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private GraphApiSyncer      $graphApiSyncer,
        private GraphFolderSyncer   $folderSyncer,
        private GraphCategorySyncer $categorySyncer,
        private GraphApiClient    $apiClient,
        private EntityManagerInterface $em,
        private LoggerInterface   $logger,
    ) {}

    public function supports(Account $account): bool
    {
        return $account->isMicrosoft();
    }

    public function sync(Account $account): array
    {
        $this->ensureImmutableIdsProbed($account);

        $folderIds = $this->folderSyncer->sync($account);

        // Categories are the many-to-many axis and are enumerated up front for
        // the same reason folders are: so every category name on an incoming
        // message already resolves to a Label.
        $this->categorySyncer->sync($account);

        if (count($folderIds) === 0) {
            $this->logger->info('GraphAccountSyncer: no syncable folders', [
                'accountId' => $account->getId(),
            ]);

            return [];
        }

        $this->graphApiSyncer->sync($account, $folderIds);

        // No Mailbox rows for Graph accounts — nothing for the caller to
        // publish per-mailbox events on.
        return [];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Probe once, at first sync, whether this mailbox honours immutable ids,
     * and remember the answer.
     *
     * A `false` here is not fatal: dedup keys on the RFC Message-ID, so the
     * account still syncs correctly — messages just get re-addressed more
     * often as they move between folders. The flag exists so that behaviour is
     * visible in admin monitoring rather than mysterious.
     */
    private function ensureImmutableIdsProbed(Account $account): void
    {
        if (null !== $account->getGraphImmutableIds()) {
            return;
        }

        try {
            $supported = $this->apiClient->probeImmutableIds($account);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphAccountSyncer: immutable id probe failed', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);

            return;
        }

        $account->setGraphImmutableIds($supported);
        $this->em->flush();

        if (false === $supported) {
            $this->logger->warning('GraphAccountSyncer: mailbox does not support immutable ids', [
                'accountId' => $account->getId(),
                'account'   => $account->getEmail(),
            ]);
        }
    }
}
