<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Exception\GraphApiException;
use App\Domain\Exception\GraphThrottledException;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Label\LabelResolver;
use App\Infrastructure\Messaging\Message\ApplyGraphChangesMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Best-effort outgoing Graph state sync.
 *
 * Everything goes through $batch at 20 per request. Graph has no batchModify
 * equivalent, and a mailbox permits only about four concurrent requests, so
 * per-message PATCHes would turn "mark 200 threads read" into a guaranteed
 * wall of 429s on an action that feels instant in the UI.
 *
 * Per-sub-request throttles are re-sliced and requeued here, because a Graph
 * batch reports a status per sub-request and a partial success has to be
 * turned into a partial retry.
 *
 * A failure of the batch *request itself* is different: nothing was applied,
 * there is nothing to re-slice, and this used to log it and return — which
 * lost the user's change outright. Transient failures now propagate so
 * Messenger redelivers the envelope; only a refusal the mailbox will keep
 * repeating is swallowed.
 *
 * The claim this docblock used to make — that the next delta pass reconciles
 * drift — is not true of an outgoing push. Graph's delta feed reports what
 * changed *in the mailbox*, so a change that never arrived produces no delta
 * to reconcile from, and the next pass overwrites the local value with the
 * server's. A swallowed push is reverted, not retried. Same reasoning as
 * ApplyGmailLabelsHandler, which had the same wrong comment.
 */
#[AsMessageHandler]
final class ApplyGraphChangesHandler
{
    /** Fallback delay when Graph did not send a Retry-After. */
    private const int RETRY_DELAY_MS = 30000;

    public function __construct(
        private readonly AccountRepository      $accountRepository,
        private readonly MessageRepository      $messageRepository,
        private readonly LabelRepository        $labelRepository,
        private readonly GraphApiClient         $apiClient,
        private readonly GraphLabelPolicy       $labelPolicy,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        private readonly LabelResolver          $labelResolver,
    ) {}

    /**
     * Exchange's own name for each standard folder, so a mailbox that has one
     * is bound to it rather than given a second folder beside it. Mirrors
     * GraphFolderSyncer's map, read the other way round.
     */
    private const array WELL_KNOWN_BY_ROLE = [
        'inbox'   => 'inbox',
        'sent'    => 'sentitems',
        'drafts'  => 'drafts',
        'trash'   => 'deleteditems',
        'spam'    => 'junkemail',
        'archive' => 'archive',
    ];

    public function __invoke(ApplyGraphChangesMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            $this->logger->warning('ApplyGraphChangesHandler: account not found', [
                'accountId' => $message->accountId,
            ]);

            return;
        }

        $messages = $this->messageRepository->findBy(['id' => $message->messageIds]);

        if (count($messages) === 0) {
            return;
        }

        $this->ensureCategoriesDefined($account, $messages);

        $throttled = $this->pushState($account, $messages);
        $throttled = array_merge($throttled, $this->pushMove($account, $messages, $message->moveToLabel));

        $this->em->flush();

        $this->requeue($account, $messages, $message->moveToLabel, $throttled);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * PATCH read state, flag state and the full category array for every
     * message in one batched pass.
     *
     * @param list<Message> $messages
     * @return list<string>  throttled graph ids
     */
    private function pushState(Account $account, array $messages): array
    {
        $patches = [];

        foreach ($messages as $entity) {
            $graphId = $entity->graphId;

            if (null === $graphId || '' === $graphId) {
                continue;
            }

            if (true === $this->labelPolicy->hasConflictingLocations($entity)) {
                $this->logger->warning('ApplyGraphChangesHandler: message holds multiple folder-backed labels', [
                    'messageId' => $entity->id,
                ]);
            }

            $patches[$graphId] = [
                'isRead'     => $entity->hasFlag(MessageFlag::SEEN),
                'flag'       => [
                    'flagStatus' => null !== $entity->starredAt ? 'flagged' : 'notFlagged',
                ],
                'categories' => $this->labelPolicy->categoryNames($entity),
            ];
        }

        if (count($patches) === 0) {
            return [];
        }

        try {
            $result = $this->apiClient->batchPatchMessages($account, $patches);
        } catch (Throwable $e) {
            $this->rethrowIfTransient($e);

            $this->logger->error('ApplyGraphChangesHandler: batch patch failed', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }

        $this->recordRefusals($account, $result, 'patch');

        return $result['throttled'];
    }

    /**
     * Which Exchange folder this label is, finding or making one if the account
     * has never had it.
     *
     * This used to be a read of graphFolderId and a warning when it was empty,
     * which meant archiving on an account whose Archive folder plMail had never
     * bound did nothing at all: the message left the inbox locally, the server
     * never heard, and the next delta put it back. A warning nobody reads is
     * not a behaviour.
     *
     * Three steps, cheapest first, and the middle one is the important one.
     * Exchange has a well-known name for each of its standard folders, so an
     * account that simply had not been folder-synced yet already *has* an
     * Archive — asking for it by name is one request and binds the real folder.
     * Creating one without asking would leave the mailbox with a second folder
     * called "Archive" beside the one Outlook already shows, which is the
     * duplicate-folder mistake in the same family as the duplicate rows.
     *
     * Creation is the last resort, and it is a real case rather than a
     * defensive one: GraphFolderSyncer already notes that `archive` is missing
     * on some mailboxes entirely.
     *
     * Either way the id is written back onto the binding, so this is asked once
     * per account and the next archive is a plain read.
     */
    private function ensureRemoteFolder(Label $label, Account $account): ?string
    {
        $binding  = $label->bindingFor($account);
        $folderId = $binding?->graphFolderId;

        if (null !== $folderId && '' !== $folderId) {
            return $folderId;
        }

        // Non-null by the time this runs: pushesAsFolder() only answers true
        // for a role label or one that already carries a graphFolderId, and the
        // second is what we have just established this is not.
        $wellKnown = self::WELL_KNOWN_BY_ROLE[$label->role->value] ?? null;

        try {
            if (null !== $wellKnown) {
                $found = $this->apiClient->resolveWellKnownFolders($account, [$wellKnown])[$wellKnown] ?? '';

                if ('' !== $found) {
                    return $this->bindFolder($label, $account, $found);
                }
            }

            $created = $this->apiClient->createFolder($account, $this->displayNameFor($label));
            $newId   = (string) ($created['id'] ?? '');

            if ('' === $newId) {
                return null;
            }

            $this->logger->info('ApplyGraphChangesHandler: created the folder this account was missing', [
                'labelId'   => $label->id,
                'accountId' => $account->id,
                'folder'    => $this->displayNameFor($label),
            ]);

            return $this->bindFolder($label, $account, $newId);
        } catch (Throwable $e) {
            $this->rethrowIfTransient($e);

            $this->logger->error('ApplyGraphChangesHandler: could not find or create the destination folder', [
                'labelId'   => $label->id,
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function bindFolder(Label $label, Account $account, string $folderId): string
    {
        $binding = $this->labelResolver->binding($label, $account);

        $binding->graphFolderId = $folderId;
        $this->em->flush();

        return $folderId;
    }

    /**
     * The leaf, because Exchange folder names are per level rather than paths —
     * the same rule ApplyLabelStructureHandler creates folders by.
     */
    private function displayNameFor(Label $label): string
    {
        $full     = (string) $label->fullName;
        $segments = explode('/', $full);

        return (string) end($segments);
    }

    /**
     * @param list<Message> $messages
     * @return list<string>  throttled graph ids
     */
    private function pushMove(Account $account, array $messages, ?int $moveToLabel): array
    {
        if (null === $moveToLabel) {
            return [];
        }

        $label = $this->labelRepository->find($moveToLabel);

        if (null === $label) {
            return [];
        }

        if (false === $this->labelPolicy->pushesAsFolder($label, $account)) {
            $this->logger->warning('ApplyGraphChangesHandler: move requested onto a non-folder label', [
                'labelId'   => $moveToLabel,
                'accountId' => $account->id,
            ]);

            return [];
        }

        $folderId = $this->ensureRemoteFolder($label, $account);

        if (null === $folderId) {
            return [];
        }

        /** @var array<string, Message> $byGraphId */
        $byGraphId = [];

        foreach ($messages as $entity) {
            $graphId = $entity->graphId;

            if (null !== $graphId && '' !== $graphId) {
                $byGraphId[$graphId] = $entity;
            }
        }

        if (count($byGraphId) === 0) {
            return [];
        }

        try {
            $result = $this->apiClient->batchMoveMessages($account, array_keys($byGraphId), $folderId);
        } catch (Throwable $e) {
            $this->rethrowIfTransient($e);

            $this->logger->error('ApplyGraphChangesHandler: batch move failed', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }

        // A move mints a new id on mailboxes without immutable-id support, so
        // keep the locator current or the next patch 404s.
        foreach ($result['moved'] as $oldId => $newId) {
            if ($oldId === $newId) {
                continue;
            }

            $entity = $byGraphId[$oldId] ?? null;

            if (null !== $entity) {
                $entity->graphId = $newId;
            }
        }

        $this->recordRefusals($account, $result, 'move');

        return $result['throttled'];
    }

    /**
     * Log the refused sub-requests, and — where it says something a user can
     * act on — record it on the account.
     *
     * WHY THIS IS NOT THE GMAIL SHAPE
     *
     * On Gmail a refusal is a thrown GmailPermanentException, so one catch was
     * enough. Graph does not work that way: a $batch POST answers HTTP 200 even
     * when every sub-request inside it was refused, so the refusals never
     * become exceptions, never reach rethrowIfTransient(), and never touch any
     * classification at all. They arrive here, in an array, and everything
     * except the bare status used to be dropped.
     *
     * WHAT IS RECORDED, AND WHAT IS NOT CLAIMED
     *
     * The account gets "a change could not be pushed, and here is what the
     * provider said". That statement is true whether the cause is permanent or
     * temporary, which matters: a 403 here can be a grant that lacks
     * Mail.ReadWrite (permanent, needs a reconnect) or a mailbox mid-migration
     * answering MailboxNotEnabledForRESTAPI (temporary, needs an hour). Nothing
     * here decides between them, and nothing here stops a retry — the next
     * export that succeeds clears the record, so a temporary cause clears
     * itself.
     *
     * @param array{throttled: list<string>, failed: array<string,int>, refusals: array<string,string>} $result
     */
    private function recordRefusals(Account $account, array $result, string $what): void
    {
        if ([] === $result['failed']) {
            // Nothing refused, so whatever was wrong is not wrong now. This is
            // what lets a temporary cause clear itself: a mailbox that was
            // mid-migration answers normally an hour later, and the card goes
            // without anybody doing anything.
            if (null !== $account->exportRefusedReason) {
                $account->exportRefusedReason = null;
                $this->em->flush();
            }

            return;
        }

        foreach ($result['failed'] as $graphId => $status) {
            $this->logger->error(sprintf('ApplyGraphChangesHandler: %s sub-request failed', $what), [
                'accountId' => $account->id,
                'graphId'   => $graphId,
                'status'    => $status,
                'code'      => $result['refusals'][$graphId] ?? null,
            ]);
        }

        // One line for the account, from the first refusal: they are almost
        // always the same fault repeated per message, and a field holding five
        // thousand copies of it helps nobody.
        $graphId = array_key_first($result['failed']);
        $status  = $result['failed'][$graphId];
        $code    = $result['refusals'][$graphId] ?? null;

        $account->exportRefusedReason = mb_substr(sprintf(
            'Microsoft refused a %s with %d%s (%d of %d changes).',
            $what,
            $status,
            null !== $code ? ' ' . $code : '',
            count($result['failed']),
            count($result['failed']) + count($result['throttled']),
        ), 0, 500);

        $this->em->flush();
    }

    /**
     * Define any category on the mailbox that has never been pushed, so it
     * renders with a colour in Outlook rather than as an undefined string.
     * Mirrors ApplyGmailLabelsHandler creating a label on Gmail first.
     *
     * @param list<Message> $messages
     */
    private function ensureCategoriesDefined(Account $account, array $messages): void
    {
        $wanted = [];

        foreach ($messages as $entity) {
            foreach ($this->labelPolicy->categoryNames($entity) as $name) {
                $wanted[$name] = true;
            }
        }

        if (count($wanted) === 0) {
            return;
        }

        try {
            $existing = [];

            foreach ($this->apiClient->listMasterCategories($account) as $category) {
                $existing[(string) ($category['displayName'] ?? '')] = true;
            }
        } catch (Throwable $e) {
            // A throttle here is the same throttle the patch below is about to
            // hit, so there is nothing to gain by pressing on and losing the
            // push instead of retrying it.
            $this->rethrowIfTransient($e);

            $this->logger->error('ApplyGraphChangesHandler: could not list master categories', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return;
        }

        foreach (array_keys($wanted) as $name) {
            if (true === array_key_exists($name, $existing)) {
                continue;
            }

            try {
                // No label in hand here — this is a category being defined so a
                // message can carry it. Colour follows on the next structure
                // sync rather than being guessed at.
                $this->apiClient->createMasterCategory($account, $name);
            } catch (Throwable $e) {
                // Retrying is safe to resume mid-loop: the categories already
                // created are skipped by the existing check above.
                $this->rethrowIfTransient($e);

                $this->logger->error('ApplyGraphChangesHandler: could not create master category', [
                    'accountId' => $account->id,
                    'category'  => $name,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Re-throw anything the mailbox is likely to answer differently in a
     * minute, so Messenger redelivers instead of this handler eating it.
     *
     * The split is the whole point of the change. A throttle, a 5xx or a
     * request that never reached Microsoft says nothing about whether the
     * change is *valid* — swallowing it discards a mutation the user made and
     * can still see locally, and nothing afterwards goes looking for it. A
     * 4xx does say something, and repeating it five times helps nobody.
     *
     * Status 0 is the client's own "no response" case, which is why it sits
     * with the 5xx rather than with the refusals.
     */
    private function rethrowIfTransient(Throwable $error): void
    {
        if ($error instanceof GraphThrottledException || $error instanceof TransportExceptionInterface) {
            throw $error;
        }

        if ($error instanceof GraphApiException
            && (0 === $error->getStatus() || $error->getStatus() >= 500)) {
            throw $error;
        }
    }

    /**
     * @param list<Message> $messages
     * @param list<string>  $throttled
     */
    private function requeue(Account $account, array $messages, ?int $moveToLabel, array $throttled): void
    {
        if (count($throttled) === 0) {
            return;
        }

        $throttled = array_values(array_unique($throttled));
        $retryIds  = [];

        foreach ($messages as $entity) {
            $graphId = $entity->graphId;

            if (null !== $graphId && true === in_array($graphId, $throttled, true)) {
                $retryIds[] = (int) $entity->id;
            }
        }

        if (count($retryIds) === 0) {
            return;
        }

        $this->logger->info('ApplyGraphChangesHandler: requeueing throttled writes', [
            'accountId' => $account->id,
            'count'     => count($retryIds),
        ]);

        $this->bus->dispatch(
            new ApplyGraphChangesMessage((int) $account->id, $retryIds, $moveToLabel),
            [new DelayStamp(self::RETRY_DELAY_MS)],
        );
    }
}
