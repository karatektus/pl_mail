<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Account;
use App\Repository\LabelBindingRepository;
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
 * ── Why roles are resolved by a separate lookup ───────────────────────────
 * The obvious implementation reads a `wellKnownName` property off each folder
 * in the list. The v1.0 endpoint refuses to project that property onto
 * microsoft.graph.mailFolder and fails the whole request with
 * "Could not find a property named 'wellKnownName'". So instead each well-known
 * name is resolved to an id up front — Graph accepts a well-known name in place
 * of an id on the mailFolders path — and the resulting id => name map is
 * consulted while walking the list.
 *
 * That is also the more correct approach: immutable ids do not cover mailFolder
 * resources, so system folder ids can rotate, and resolving by name every sync
 * means a rotated id re-links itself.
 */
final readonly class GraphFolderSyncer
{
    /**
     * Well-known names we resolve to ids. Ones the mailbox does not have are
     * skipped silently by the client — `archive` is missing on some mailboxes,
     * and the recoverable-items folders are absent on consumer accounts.
     */
    private const array WELL_KNOWN_NAMES = [
        'msgfolderroot',
        'inbox',
        'sentitems',
        'drafts',
        'deleteditems',
        'junkemail',
        'archive',
        'outbox',
        'searchfolders',
        'conversationhistory',
        'recoverableitemsdeletions',
        'serverfailures',
        'syncissues',

    ];

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
        'archive'    => LabelRole::Archive,  // enable if LabelRole::Archive exists
    ];

    public function __construct(
        private GraphApiClient         $apiClient,
        private LabelResolver          $labelResolver,
        private LabelBindingRepository $bindingRepository,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /**
     * @return list<string>  ids of folders whose messages should be synced
     */
    public function sync(Account $account): array
    {
        $folders = $this->apiClient->listFolders($account);

        // wellKnownName => folderId, then inverted so the walk can ask
        // "what role, if any, does this folder id have?"
        $wellKnownByName = $this->apiClient->resolveWellKnownFolders($account, self::WELL_KNOWN_NAMES);
        $nameByFolderId  = array_flip($wellKnownByName);
        $rootId          = $wellKnownByName['msgfolderroot'] ?? '';

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
            $wellKnown = $nameByFolderId[$id] ?? '';

            if (true === in_array($wellKnown, self::IGNORED_WELL_KNOWN, true)) {
                continue;
            }

            $label = null;

            if (true === array_key_exists($wellKnown, self::SYSTEM_MAP)) {
                $label = $this->labelResolver->systemLabel(self::SYSTEM_MAP[$wellKnown], $account);
            } else {
                $segments = $this->nameChain($id, $byId, $rootId);

                if (count($segments) === 0) {
                    continue;
                }

                $label = $this->labelResolver->customChain($segments, $account);
            }

            if (null === $label) {
                continue;
            }

            // Folder ids are mutable, so re-link whenever it has drifted.
            $binding = $this->labelResolver->binding($label, $account);

            if ($binding->graphFolderId !== $id) {
                $binding->graphFolderId = $id;
            }

            $syncable[] = $id;
            $synced++;
        }

        // Drop stale links: a binding pointing at a folder that no longer
        // exists keeps its row (the user may have local mail filed under the
        // label) but loses the dead id so it cannot mis-resolve.
        foreach ($this->bindingRepository->findWithGraphFolderIdForAccount($account) as $binding) {
            $linkedId = $binding->graphFolderId;

            if (null === $linkedId) {
                continue;
            }

            if (false === array_key_exists($linkedId, $byId)) {
                $binding->graphFolderId = null;
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
     * @param string                             $rootId  msgfolderroot id, walk stops there
     * @return list<string>
     */
    private function nameChain(string $folderId, array $byId, string $rootId): array
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

            if ('' !== $rootId && $cursor === $rootId) {
                break;
            }

            $folder = $byId[$cursor];
            $name   = trim((string) ($folder['displayName'] ?? ''));

            if ('' !== $name) {
                array_unshift($segments, $name);
            }

            $cursor = (string) ($folder['parentFolderId'] ?? '');
        }

        return $segments;
    }
}
