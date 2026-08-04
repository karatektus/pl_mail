<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use App\Entity\Calendar\EventProposal;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\EventProposalRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The proposal a message carries, or nothing.
 *
 * The read side of the feature, and the sibling of InviteReader down to its
 * caching, for the reason that class gives at length: the card is drawn by a
 * partial included once per message, so a lookup keyed on the message would be
 * an indexed query per row on every conversation anybody opens — to answer "no"
 * for nearly all of them. The first question about any message in a thread
 * loads the whole thread's proposals; the rest are answered from memory.
 *
 * Ownership is checked here rather than at the call site, because the call site
 * is a template holding whatever message it was handed. The account's user is
 * the authorisation; the route is not.
 *
 * Nothing is filtered by now(). A proposal whose date has since gone by is
 * still shown, and that is deliberate: judging it against the clock would make
 * the same message show a card today and not tomorrow, with nothing in the row
 * to explain the difference. Whether the date was in the future is decided once,
 * against the message's own date, where the guess is made.
 *
 * Resettable rather than merely per-request by convention: this holds entities,
 * and under a worker runtime a cache that outlives its request hands out
 * objects belonging to a closed entity manager.
 */
final class ProposalReader implements ResetInterface
{
    /**
     * Message id to its proposal, including the nulls — "asked and there is
     * none" has to be distinguishable from "not asked yet", or every miss is
     * re-queried.
     *
     * @var array<int, EventProposal|null>
     */
    private array $proposals = [];

    /** @var array<int, true> thread ids already loaded */
    private array $loadedThreads = [];

    public function __construct(
        private readonly EventProposalRepository $repository,
    ) {
    }

    public function forMessage(Message $message, ?User $user): ?EventProposal
    {
        $id = $message->id;

        if (null === $id || false === $user instanceof User) {
            return null;
        }

        if ($message->account->usr !== $user) {
            return null;
        }

        if (true === array_key_exists($id, $this->proposals)) {
            return $this->proposals[$id];
        }

        foreach ($this->load($message) as $proposal) {
            $proposedFor = $proposal->message->id;

            if (null === $proposedFor) {
                continue;
            }

            // First proposal per message wins. The unique constraint allows a
            // message to hold several instants and EventProposer never writes a
            // second one; if that ever changes, the card shows the first rather
            // than growing a list nobody asked to judge.
            $this->proposals[$proposedFor] ??= $proposal;
        }

        return $this->proposals[$id] ??= null;
    }

    public function reset(): void
    {
        $this->proposals     = [];
        $this->loadedThreads = [];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return list<EventProposal>
     */
    private function load(Message $message): array
    {
        $thread = $message->thread;

        if (null === $thread || null === $thread->id) {
            return $this->repository->findForMessage($message);
        }

        if (true === array_key_exists($thread->id, $this->loadedThreads)) {
            return [];
        }

        $this->loadedThreads[$thread->id] = true;

        return $this->repository->findForThread($thread);
    }
}
