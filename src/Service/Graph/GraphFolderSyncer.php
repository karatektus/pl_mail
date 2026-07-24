<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\Enum\LabelRole;
use App\Entity\Account;
use App\Repository\LabelRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs the Graph mailFolders list into local Label rows for an account.
 * Runs before message sync so every parentFolderId on an incoming message
 * resolves to an existing Label.
 *
 * Mapping:
 *   - wellKnownName (inbox, sentitems, drafts, deleteditems, junkemail, …)
 *     → role labels.
 *   - everything else → nested custom label chains built by walking
 *     parentFolderId up to msgfolderroot.
 *
 * Graph folders are a real hierarchy (single parent, exclusive membership),
 * unlike Gmail labels — so unlike GmailLabelSyncer there is no "Work/Invoices"
 * name-splitting here. The chain comes from the tree itself, which is more
 * reliable: a folder literally named "A/B" would break the Gmail approach but
 * is handled correctly here.
 *
 * Immutable IDs do NOT cover mailFolder resources, so folder ids can change.
 * System folders are therefore always re-resolved by wellKnownName rather than
 * trusting a stored id, and a custom folder whose id has rotated is re-linked
 * by its name chain on the next run.
 */
final readonly class GraphFolderSyncer
{
    /**
     * Folders we deliberately do not model as labels — Exchange plumbing that
     * has no user-facing meaning.
     */
    private const array IGNORED_WELL_KNOWN = [
        'msgfolderroot',
        'searchfolders',
        'recoverableitemsdeletions',
        'serverfailures',
        'syncissues',
        'conflicts',
        'localfailures',
        'outbox',
    ];

    private const array SYSTEM_MAP = [
        'inbox'         => LabelRole::Inbox,
        'sentitems'     => LabelRole::Sent,
        'drafts'        => LabelRole::Drafts,
        'deleteditems'  => LabelRole::Trash,
        'junkemail'     => LabelRole::Spam,
        // 'archive'    => LabelRole::Archive,  // enable if LabelRole::Archive exists
    ];

    public function __construct(
        private GraphApiClient         $apiClient,
        private LabelResolver          $labelResolver,
        private LabelRepository        $labelRepository,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /**
     * @return list<string>  ids of folders whose messages should be synced
     */
    public function sync(Account $account): array
    {
        $folders = $this->apiClient->listFolders($account);

        /** @var array<string, array<string,mixed>> $byId */
        $byId = [];

        foreach ($folders as $folder) {
            $id = (string) ($folder['id'] ?? '');

            if ('' === $id) {
                continue;
            }

            $byId[$id] = $folder;
        }

        $syncable = [];
        $synced   = 0;

        foreach ($byId as $id => $folder) {
            $wellKnown = strtolower((string) ($folder['wellKnownName'] ?? ''));

            if (true === in_array($wellKnown, self::IGNORED_WELL_KNOWN, true)) {
                continue;
            }

            $label = null;

            if (true === array_key_exists($wellKnown, self::SYSTEM_MAP)) {
                $label = $this->labelResolver->systemLabel(self::SYSTEM_MAP[$wellKnown], $account);
            } else {
                $segments = $this->nameChain($id, $byId);

                if (count($segments) === 0) {
                    continue;
                }

                $label = $this->labelResolver->customChain($segments, $account);
            }

            if (null === $label) {
                continue;
            }

            // Folder ids are mutable, so re-link whenever it has drifted.
            if ($label->graphFolderId !== $id) {
                $label->setGraphFolderId($id);
            }

            $syncable[] = $id;
            $synced++;
        }

        // Drop stale links: a label pointing at a folder that no longer exists
        // keeps its row (the user may have local mail filed under it) but loses
        // the dead id so it cannot mis-resolve.
        foreach ($this->labelRepository->findWithGraphFolderIdForAccount($account) as $label) {
            $linkedId = $label->graphFolderId;

            if (null === $linkedId) {
                continue;
            }

            if (false === array_key_exists($linkedId, $byId)) {
                $label->setGraphFolderId(null);
            }
        }

        $this->em->flush();

        $this->logger->info('GraphFolderSyncer: folders synced', [
            'accountId' => $account->getId(),
            'folders'   => count($byId),
            'linked'    => $synced,
        ]);

        return $syncable;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Walk parentFolderId upward, collecting display names root-first.
     *
     * @param array<string, array<string,mixed>> $byId
     * @return list<string>
     */
    private function nameChain(string $folderId, array $byId): array
    {
        $segments = [];
        $cursor   = $folderId;
        $guard    = 0;

        while ('' !== $cursor && true === array_key_exists($cursor, $byId)) {
            $guard++;

            // Defensive: a cycle in the folder tree would otherwise hang sync.
            if ($guard > 32) {
                break;
            }

            $folder    = $byId[$cursor];
            $wellKnown = strtolower((string) ($folder['wellKnownName'] ?? ''));

            if ('msgfolderroot' === $wellKnown) {
                break;
            }

            $name = trim((string) ($folder['displayName'] ?? ''));

            if ('' !== $name) {
                array_unshift($segments, $name);
            }

            $cursor = (string) ($folder['parentFolderId'] ?? '');
        }

        return $segments;
    }
}
