<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Exception\GraphApiException;
use App\Domain\Exception\GraphThrottledException;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
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
    ) {}

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
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);

            return [];
        }

        foreach ($result['failed'] as $graphId => $status) {
            $this->logger->error('ApplyGraphChangesHandler: patch sub-request failed', [
                'accountId' => $account->getId(),
                'graphId'   => $graphId,
                'status'    => $status,
            ]);
        }

        return $result['throttled'];
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
                'accountId' => $account->getId(),
            ]);

            return [];
        }

        $folderId = $label->bindingFor($account)?->graphFolderId;

        if (null === $folderId || '' === $folderId) {
            $this->logger->warning('ApplyGraphChangesHandler: folder label has no graphFolderId on this account', [
                'labelId'   => $moveToLabel,
                'accountId' => $account->getId(),
            ]);

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
                'accountId' => $account->getId(),
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

        foreach ($result['failed'] as $graphId => $status) {
            $this->logger->error('ApplyGraphChangesHandler: move sub-request failed', [
                'accountId' => $account->getId(),
                'graphId'   => $graphId,
                'status'    => $status,
            ]);
        }

        return $result['throttled'];
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
                'accountId' => $account->getId(),
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
                    'accountId' => $account->getId(),
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
            'accountId' => $account->getId(),
            'count'     => count($retryIds),
        ]);

        $this->bus->dispatch(
            new ApplyGraphChangesMessage((int) $account->getId(), $retryIds, $moveToLabel),
            [new DelayStamp(self::RETRY_DELAY_MS)],
        );
    }
}
