<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Infrastructure\Messaging\Message\ApplyGmailLabelsMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Domain\Enum\Mail\LabelColor;
use App\Domain\Exception\GmailPermanentException;
use App\Domain\Exception\GmailThrottledException;
use App\Service\Gmail\GmailLabelColorMapper;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GmailApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Outgoing Gmail label sync via messages.batchModify.
 *
 * Numeric entries in add/remove are local Label ids; those are resolved to
 * their gmailLabelId, creating the label on Gmail first when it has never
 * been pushed (labels created locally in plMail).
 *
 * Failures are split rather than uniformly swallowed. A quota rejection is
 * allowed out of the handler so Messenger redelivers it after the delay the
 * exception carries; swallowing it loses the push outright, because nothing
 * pulls it back. Gmail's history feed reports what happened *in* the mailbox,
 * so a change that never reached Gmail is not drift sync can reconcile — the
 * next incremental pass sees Gmail's unchanged state and, for anything sync
 * writes back, overwrites the local value instead.
 *
 * A permanent refusal — a scope the grant never included, a suspended account
 * — is logged and swallowed, because it answers identically on every attempt
 * and redelivering it only buries the one log line that explains it. There the
 * DB stays the source of truth and the divergence is real but unfixable from
 * here.
 */
#[AsMessageHandler]
final class ApplyGmailLabelsHandler
{
    public function __construct(
        private readonly AccountRepository      $accountRepository,
        private GmailLabelColorMapper  $colorMapper,
        private readonly MessageRepository      $messageRepository,
        private readonly LabelRepository        $labelRepository,
        private readonly GmailApiClient         $apiClient,
        private readonly LabelResolver          $labelResolver,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(ApplyGmailLabelsMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            $this->logger->warning('ApplyGmailLabelsHandler: account not found', [
                'accountId' => $message->accountId,
            ]);

            return;
        }

        $gmailIds = $this->collectGmailIds($message->messageIds);

        if (count($gmailIds) === 0) {
            return;
        }

        try {
            $addLabelIds    = $this->resolveGmailLabelIds($message->add, $account);
            $removeLabelIds = $this->resolveGmailLabelIds($message->remove, $account);

            if (count($addLabelIds) === 0 && count($removeLabelIds) === 0) {
                return;
            }

            $this->apiClient->batchModify($account, $gmailIds, $addLabelIds, $removeLabelIds);

            // It worked, so whatever was wrong is not wrong now. Cleared here
            // rather than left to expire, because the health card built from it
            // has to describe the present.
            if (null !== $account->exportRefusedReason) {
                $account->exportRefusedReason = null;
                $this->em->flush();
            }
        } catch (GmailPermanentException $e) {
            // Deliberately the only failure that stops here. Anything else —
            // a quota rejection, a 5xx, a dropped connection — leaves the
            // handler so the transport redelivers it; caught here, the user's
            // archive or star would be applied locally and never anywhere else.
            $this->logger->error('ApplyGmailLabelsHandler: batchModify refused permanently', [
                'accountId' => $account->id,
                'add'       => $message->add,
                'remove'    => $message->remove,
                'reason'    => $e->getReason(),
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            // AND SAID WHERE SOMEBODY WILL SEE IT.
            //
            // A log line was the whole of it, and the consequence is not small:
            // the change is applied here and refused there, so marking five
            // thousand conversations read appears to work, never reaches Gmail,
            // and is undone by the next sync. From the outside that is a button
            // that does nothing, twice, with no explanation available anywhere
            // a user can reach.
            //
            // AccountHealthInspector turns this into a card with a reconnect on
            // it, because for the reason this actually happens —
            // insufficientPermissions, a grant that was never given the scope
            // to write — reconnecting IS the repair.
            $account->exportRefusedReason = mb_substr($e->getMessage(), 0, 500);
            $this->em->flush();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param int[] $messageIds
     * @return list<string>
     */
    private function collectGmailIds(array $messageIds): array
    {
        $messages = $this->messageRepository->findBy(['id' => $messageIds]);
        $gmailIds = [];

        foreach ($messages as $msg) {
            $gmailId = $msg->gmailId;

            if (null !== $gmailId && '' !== $gmailId) {
                $gmailIds[] = $gmailId;
            }
        }

        return $gmailIds;
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    private function resolveGmailLabelIds(array $entries, Account $account): array
    {
        $resolved = [];

        foreach ($entries as $entry) {
            if (false === ctype_digit($entry)) {
                // Gmail system label id — use verbatim.
                $resolved[] = $entry;
                continue;
            }

            $label = $this->labelRepository->find((int) $entry);

            if (null === $label || $label->usr !== $account->usr) {
                $this->logger->warning('ApplyGmailLabelsHandler: label not found for user', [
                    'labelId'   => $entry,
                    'accountId' => $account->id,
                ]);
                continue;
            }

            $gmailLabelId = $this->ensureRemoteLabel($label, $account);

            if (null !== $gmailLabelId) {
                $resolved[] = $gmailLabelId;
            }
        }

        return $resolved;
    }

    /**
     * Returns the label's gmailLabelId, creating the label on Gmail first
     * when it only exists locally. Gmail nesting is by name convention, so
     * the created label's name is the full "Parent/Child" path.
     */
    private function ensureRemoteLabel(Label $label, Account $account): ?string
    {
        // Per-account: the same label may already be pushed to one Gmail
        // account and still be local-only on another.
        $binding = $this->labelResolver->binding($label, $account);

        if (null !== $binding->gmailLabelId) {
            return $binding->gmailLabelId;
        }

        try {
            $created      = $this->apiClient->createLabel(
                $account,
                $label->fullName,
                $this->colorMapper->toGmailColor(LabelColor::tryFrom((string) $label->color)),
            );
            $gmailLabelId = (string) ($created['id'] ?? '');

            if ('' === $gmailLabelId) {
                return null;
            }

            $binding->gmailLabelId = $gmailLabelId;
            $this->em->flush();

            return $gmailLabelId;
        } catch (GmailThrottledException $e) {
            // Not swallowed like the rest: returning null here drops the label
            // from the batch, so the push would go out looking successful while
            // silently missing exactly the label the user asked for. Let the
            // whole message be redelivered instead — the binding is flushed as
            // soon as a label is created, so the retry skips the ones that
            // already made it.
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('ApplyGmailLabelsHandler: remote label creation failed', [
                'labelId'   => $label->id,
                'name'      => $label->fullName,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
