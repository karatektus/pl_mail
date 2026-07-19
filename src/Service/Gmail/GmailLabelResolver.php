<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Account;
use App\Entity\Label;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps a Gmail message's labelIds onto local Label entities.
 *
 * Replaces GmailLabelMailboxRouter: instead of routing N labels down to one
 * mailbox, every mapped label is returned — a Gmail message simply carries
 * all of them. Unmapped ids (STARRED, UNREAD, IMPORTANT, CATEGORY_*, or a
 * Label_xxx created remotely after the last GmailLabelSyncer run) are
 * skipped; the next label sync picks up stragglers and the message's stored
 * gmailLabelIds allow re-resolution later if ever needed.
 *
 * Caches label IDs (never entities) per account so batch handlers survive
 * flush()/clear() cycles without detached entity errors.
 */
final class GmailLabelResolver
{
    /** @var array<int, array<string, int|null>> accountId → gmailLabelId → labelId|null */
    private array $idCache = [];

    public function __construct(
        private readonly LabelRepository        $labelRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * @param list<string> $labelIds
     * @return list<Label>
     */
    public function resolve(array $labelIds, Account $account): array
    {
        $labels = [];

        foreach ($labelIds as $labelId) {
            $label = $this->resolveOne($labelId, $account);

            if (null !== $label) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolveOne(string $gmailLabelId, Account $account): ?Label
    {
        $accountId = (int) $account->getId();

        if (true === array_key_exists($gmailLabelId, $this->idCache[$accountId] ?? [])) {
            $cachedId = $this->idCache[$accountId][$gmailLabelId];

            if (null === $cachedId) {
                return null;
            }

            return $this->em->find(Label::class, $cachedId);
        }

        $label = $this->labelRepository->findOneByGmailLabelId($gmailLabelId, $account);

        if (null === $label) {
            // Only warn for ids that look like user labels — system ids we
            // deliberately don't model (STARRED etc.) are expected misses.
            if (true === str_starts_with($gmailLabelId, 'Label_')) {
                $this->logger->warning('GmailLabelResolver: unknown user label id, will map on next label sync', [
                    'accountId'    => $accountId,
                    'gmailLabelId' => $gmailLabelId,
                ]);
            }

            $this->idCache[$accountId][$gmailLabelId] = null;

            return null;
        }

        $this->idCache[$accountId][$gmailLabelId] = (int) $label->id;

        return $label;
    }
}
