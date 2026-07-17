<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Enum\MailboxSpecialUse;
use App\Entity\Account;
use App\Entity\Mailbox;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Determines which local Mailbox a Gmail message belongs in based on its
 * labelIds, using a fixed priority order that mirrors how Gmail itself
 * surfaces messages in its folder-like views.
 *
 * Priority (first match wins):
 *   DRAFT  → Drafts
 *   TRASH  → Trash
 *   SPAM   → Junk
 *   SENT   → Sent
 *   INBOX  → Inbox
 *   (none) → Archive  (has no INBOX label — e.g. archived messages)
 *
 * Mailbox IDs are cached per account to avoid redundant DB lookups within
 * a batch, but entities are always re-fetched by ID to guarantee they are
 * managed by the current EntityManager unit of work. This prevents detached
 * entity errors after flush() clears the identity map.
 */
final class GmailLabelMailboxRouter
{
    /** @var array<int, array<string, int|null>> accountId → specialUse-value → mailboxId|null */
    private array $idCache = [];

    public function __construct(
        private readonly MailboxRepository      $mailboxRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param list<string> $labelIds  Gmail label IDs from the message payload
     */
    public function resolve(array $labelIds, Account $account): ?Mailbox
    {
        $specialUse = $this->labelIdsToSpecialUse($labelIds);

        return $this->mailboxForSpecialUse($specialUse, $account);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<string> $labelIds
     */
    private function labelIdsToSpecialUse(array $labelIds): MailboxSpecialUse
    {
        if (true === in_array('DRAFT', $labelIds, true)) {
            return MailboxSpecialUse::DRAFTS;
        }

        if (true === in_array('TRASH', $labelIds, true)) {
            return MailboxSpecialUse::TRASH;
        }

        if (true === in_array('SPAM', $labelIds, true)) {
            return MailboxSpecialUse::JUNK;
        }

        if (true === in_array('SENT', $labelIds, true)) {
            return MailboxSpecialUse::SENT;
        }

        if (true === in_array('INBOX', $labelIds, true)) {
            return MailboxSpecialUse::INBOX;
        }

        // Archived messages have none of the above labels.
        return MailboxSpecialUse::ARCHIVE;
    }

    private function mailboxForSpecialUse(MailboxSpecialUse $specialUse, Account $account): ?Mailbox
    {
        $accountId = (int) $account->getId();
        $key       = $specialUse->value;

        if (false === isset($this->idCache[$accountId])) {
            $this->idCache[$accountId] = [];
        }

        if (false === array_key_exists($key, $this->idCache[$accountId])) {
            $mailbox = $this->mailboxRepository->findOneBy([
                'account'    => $account,
                'specialUse' => $specialUse,
            ]);

            $this->idCache[$accountId][$key] = $mailbox?->getId();
        }

        $id = $this->idCache[$accountId][$key];

        if (null === $id) {
            return null;
        }

        // Always re-fetch by ID so the returned entity is managed by the
        // current EM unit of work, regardless of whether flush() has been
        // called since the ID was cached.
        return $this->em->find(Mailbox::class, $id);
    }
}
