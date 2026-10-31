<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Repository\Label\LabelBindingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Maps the mailbox ids on a JMAP Email onto local Labels.
 *
 * The counterpart of GmailLabelResolver: an Email's `mailboxIds` is a set, as
 * a Gmail message's labelIds is, and every member becomes a label. A mailbox
 * id no binding knows — one created on another client since the last mailbox
 * sync — resolves to nothing rather than to a guess; the next sync binds it,
 * and the next change to the message applies it.
 *
 * Caches label ids, never entities, per account and per Messenger message:
 * the batch handlers clear the entity manager between pages, and a cached
 * entity would come back detached.
 */
final class JmapLabelResolver implements ResetInterface
{
    /** @var array<int, array<string, int>> account id → mailbox id → label id */
    private array $labelIds = [];

    public function __construct(
        private readonly LabelBindingRepository $bindings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $mailboxIds the Email's `mailboxIds`: id => true
     *
     * @return list<Label>
     */
    public function resolve(array $mailboxIds, Account $account): array
    {
        $map    = $this->mapFor($account);
        $labels = [];

        foreach ($mailboxIds as $mailboxId => $member) {
            if (true !== $member) {
                continue;
            }

            $labelId = $map[(string) $mailboxId] ?? null;
            $label   = null === $labelId ? null : $this->em->find(Label::class, $labelId);

            if (null !== $label) {
                $labels[(int) $label->id] = $label;
            }
        }

        return array_values($labels);
    }

    /**
     * The remote mailbox id a label is bound to on this account, or null.
     */
    public function mailboxIdFor(Label $label, Account $account): ?string
    {
        return $label->bindingFor($account)?->jmapMailboxId;
    }

    /**
     * Drop what is known about an account, after its mailbox list changed.
     */
    public function forget(Account $account): void
    {
        unset($this->labelIds[(int) $account->id]);
    }

    public function reset(): void
    {
        $this->labelIds = [];
    }

    /**
     * @return array<string, int>
     */
    private function mapFor(Account $account): array
    {
        $accountId = (int) $account->id;

        if (false === isset($this->labelIds[$accountId])) {
            $map = [];

            foreach ($this->bindings->findByJmapMailboxIdForAccount($account) as $mailboxId => $binding) {
                $map[$mailboxId] = (int) $binding->label->id;
            }

            $this->labelIds[$accountId] = $map;
        }

        return $this->labelIds[$accountId];
    }
}
