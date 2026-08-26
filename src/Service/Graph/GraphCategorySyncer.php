<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Mail\Account;
use App\Service\Label\LabelResolver;
use App\Domain\Exception\GraphApiException;
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
 * Colour comes across, but only onto labels that have none. Graph uses
 * preset0–preset24 rather than hex, so the map is lossy in one direction and
 * would drift if it ran both ways on every sync; writing it only when there is
 * nothing to overwrite removes the round trip that causes that. See
 * GraphCategoryColorMapper.
 */
final readonly class GraphCategorySyncer
{
    public function __construct(
        private GraphApiClient           $apiClient,
        private LabelResolver            $labelResolver,
        private GraphCategoryColorMapper $colorMapper,
        private EntityManagerInterface   $em,
        private LoggerInterface          $logger,
    ) {}

    public function sync(Account $account): void
    {
        try {
            $categories = $this->apiClient->listMasterCategories($account);
        } catch (GraphApiException $e) {
            // 403 here almost always means the MailboxSettings.ReadWrite scope
            // is missing rather than anything being broken: master categories
            // live under the Outlook user-settings resource, not under Mail.*.
            // Degrade quietly — folders, messages and send are unaffected, only
            // the category axis is unavailable.
            if (403 === $e->getStatus()) {
                $this->logger->warning(
                    'GraphCategorySyncer: no access to master categories — '
                    . 'the account likely needs reconnecting with MailboxSettings.ReadWrite',
                    ['accountId' => $account->id],
                );

                return;
            }

            $this->logger->error('GraphCategorySyncer: listing failed', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return;
        } catch (\Throwable $e) {
            $this->logger->error('GraphCategorySyncer: listing failed', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return;
        }

        $created = 0;
        $colored = 0;

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

                // The category's id, recorded on the binding.
                //
                // Not decoration: without it, renaming this label in plMail had
                // nothing to address at the provider, fell through to the
                // create branch, and produced a SECOND category under the new
                // name while the old one stayed — which the next run of this
                // very method then imported back as a label beside the renamed
                // one. See ApplyLabelStructureHandler::renameGraph().
                //
                // Deliberately NOT graphFolderId, where the create path used to
                // put it: GraphLabelPolicy reads that column to mean "this label
                // is an Exchange folder", so a category recorded there starts
                // being pushed as a location. See LabelBinding.
                //
                // Only for the LEAF of a "Work/Invoices" chain — the ancestors
                // are labels plMail invented to hold the tree, and no category
                // on the remote corresponds to them.
                $categoryId = trim((string) ($category['id'] ?? ''));

                if ('' !== $categoryId) {
                    $this->labelResolver->binding($label, $account)->graphCategoryId = $categoryId;
                }

                // Only onto a label with no colour: a colour picked in plMail
                // outranks the one Outlook happens to carry, and re-reading it
                // on every sync is exactly the round trip that would make a
                // 25-to-9 map drift.
                if (null === $label->color) {
                    $mapped = $this->colorMapper->toLabelColor($category['color'] ?? null);

                    if (null !== $mapped) {
                        $label->color = $mapped->value;
                        $colored++;
                    }
                }
            }
        }

        $this->em->flush();

        $this->logger->info('GraphCategorySyncer: categories synced', [
            'accountId'  => $account->id,
            'categories' => count($categories),
            'linked'     => $created,
            'colored'    => $colored,
        ]);
    }
}
