<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Account;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs the mailbox's master categories into local Label rows.
 *
 * The counterpart to GraphFolderSyncer, and the analogue of GmailLabelSyncer's
 * handling of type=user labels. It closes an asymmetry that would otherwise
 * exist: folders auto-create labels, so without this a category created in
 * Outlook would never surface in plMail.
 *
 * Enumerating up front — rather than discovering categories from individual
 * messages' `categories` arrays during import — is the whole point. Discovery
 * from messages would trickle labels in one at a time as mail imports, with no
 * complete list.
 *
 * Colour is deliberately not mapped. Graph uses preset0–preset24 rather than
 * hex, and a lossy bidirectional mapping would drift on every sync.
 */
final readonly class GraphCategorySyncer
{
    public function __construct(
        private GraphApiClient         $apiClient,
        private LabelResolver          $labelResolver,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function sync(Account $account): void
    {
        try {
            $categories = $this->apiClient->listMasterCategories($account);
        } catch (\Throwable $e) {
            $this->logger->error('GraphCategorySyncer: listing failed', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);

            return;
        }

        $created = 0;

        foreach ($categories as $category) {
            $displayName = trim((string) ($category['displayName'] ?? ''));

            if ('' === $displayName) {
                continue;
            }

            // "Work/Invoices" round-trips back into the nested chain it was
            // pushed from. A category with no slash simply becomes a top-level
            // custom label.
            $label = $this->labelResolver->customChain(explode('/', $displayName), $account);

            if (null !== $label) {
                $created++;
            }
        }

        $this->em->flush();

        $this->logger->info('GraphCategorySyncer: categories synced', [
            'accountId'  => $account->getId(),
            'categories' => count($categories),
            'linked'     => $created,
        ]);
    }
}
