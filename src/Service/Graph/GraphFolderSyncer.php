<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Repository\Label\LabelBindingRepository;
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
 * resources, so system folder ids can rotate, and resolving by name means a
 * rotated id re-links itself. It is not done on every sync, though — it costs
 * thirteen requests — but whenever the folder list's ids have changed, which a
 * rotation does, and once a day. See wellKnownFolders().
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

    /**
     * How long the kept answer to "which folder is the Inbox" is trusted when
     * the folder list has not changed. See wellKnownFolders().
     */
    private const int WELL_KNOWN_MAX_AGE = 86_400;

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

        /** @var array<string, array<string,mixed>> $byId */
        $byId = [];

        foreach ($folders as $folder) {
            $id = (string) ($folder['id'] ?? '');

            if ('' === $id) {
                continue;
            }

            $byId[$id] = $folder;
        }

        // wellKnownName => folderId, then inverted so the walk can ask
        // "what role, if any, does this folder id have?"
        $wellKnownByName = $this->wellKnownFolders($account, array_keys($byId));
        $nameByFolderId  = array_flip($wellKnownByName);
        $rootId          = $wellKnownByName['msgfolderroot'] ?? '';

        $syncable = [];
        $synced   = 0;
        $messages = 0;

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
            $messages += max(0, (int) ($folder['totalItemCount'] ?? 0));
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

        // What the progress line of an import measures itself against. The
        // folder list already says it, so it is kept current here rather than
        // asked for separately.
        $account->importTotal = $messages;

        $this->em->flush();

        $this->logger->info('GraphFolderSyncer: folders synced', [
            'accountId' => $account->id,
            'folders'   => count($byId),
            'linked'    => $synced,
        ]);

        return $syncable;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Which folder is the Inbox, Sent and the rest: kept on the account, and
     * asked of Graph again only when it may have changed.
     *
     * Asking costs a request per name (see Account::$graphWellKnownFolders),
     * and it used to be done on every sync. The answer changes when a folder
     * id does — Exchange can rotate a system folder's id, which is why it is
     * never stored on its own — and a changed id changes the folder list this
     * sync has just read anyway. So the list is the check: its ids are
     * fingerprinted, and a different fingerprint, a folder created, deleted or
     * re-addressed anywhere, asks again in this same sync. Nothing waits for a
     * later one. Renaming a folder, or the mailbox's language turning "Inbox"
     * into "Posteingang", keeps its id and needs nothing.
     *
     * Once a day it is asked regardless, for whatever the fingerprint cannot
     * see: a well-known folder that is not in the list at all, such as the
     * root.
     *
     * @param list<string> $folderIds every id in the folder list just read
     *
     * @return array<string, string> wellKnownName => folderId
     */
    private function wellKnownFolders(Account $account, array $folderIds): array
    {
        sort($folderIds);

        $listing = sha1(implode("\n", $folderIds));
        $kept    = $account->graphWellKnownFolders;
        $now     = time();

        if (null !== $kept && $kept['listing'] === $listing && $now - $kept['at'] < self::WELL_KNOWN_MAX_AGE) {
            return $kept['folders'];
        }

        // A throttle part-way through escapes from here, and nothing is kept:
        // a map with a gap in it would have the sync file the missing system
        // folder as a custom one until the next refresh.
        $folders = $this->apiClient->resolveWellKnownFolders($account, self::WELL_KNOWN_NAMES);

        $account->graphWellKnownFolders = [
            'folders' => $folders,
            'listing' => $listing,
            'at'      => $now,
        ];

        return $folders;
    }

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
