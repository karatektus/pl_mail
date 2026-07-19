<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Account;
use App\Entity\Label;
use App\Message\ApplyGmailLabelsMessage;
use App\Repository\AccountRepository;
use App\Repository\LabelRepository;
use App\Repository\MessageRepository;
use App\Service\Mail\GmailApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Best-effort outgoing Gmail label sync via messages.batchModify.
 *
 * Numeric entries in add/remove are local Label ids; those are resolved to
 * their gmailLabelId, creating the label on Gmail first when it has never
 * been pushed (labels created locally in plMail). Failures are logged and
 * swallowed — DB is the source of truth, incremental sync reconciles drift.
 */
#[AsMessageHandler]
final class ApplyGmailLabelsHandler
{
    public function __construct(
        private readonly AccountRepository      $accountRepository,
        private readonly MessageRepository      $messageRepository,
        private readonly LabelRepository        $labelRepository,
        private readonly GmailApiClient         $apiClient,
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
        } catch (Throwable $e) {
            $this->logger->error('ApplyGmailLabelsHandler: batchModify failed', [
                'accountId' => $account->getId(),
                'add'       => $message->add,
                'remove'    => $message->remove,
                'error'     => $e->getMessage(),
            ]);
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
            $gmailId = $msg->getGmailId();

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

            if (null === $label || $label->account !== $account) {
                $this->logger->warning('ApplyGmailLabelsHandler: label not found for account', [
                    'labelId'   => $entry,
                    'accountId' => $account->getId(),
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
        if (null !== $label->gmailLabelId) {
            return $label->gmailLabelId;
        }

        try {
            $created      = $this->apiClient->createLabel($account, $label->fullName);
            $gmailLabelId = (string) ($created['id'] ?? '');

            if ('' === $gmailLabelId) {
                return null;
            }

            $label->setGmailLabelId($gmailLabelId);
            $this->em->flush();

            return $gmailLabelId;
        } catch (Throwable $e) {
            $this->logger->error('ApplyGmailLabelsHandler: remote label creation failed', [
                'labelId' => $label->id,
                'name'    => $label->fullName,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
