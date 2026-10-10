<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Mail\Account;
use Symfony\Component\Clock\ClockInterface;

/**
 * Spaces out the bulk Gmail calls so an import stays inside the account's
 * quota instead of finding its edge by being refused.
 *
 * Google allows one Gmail user 15,000 quota units a minute, across everything
 * done in that user's name. Fetching a message costs 5, so a batch of a
 * hundred is 500 and thirty batches are the whole minute. Nothing used to
 * count: the worker fetched one batch after another as fast as Google
 * answered. While the same worker also did the follow-up work for every
 * message, that was slow enough to stay under the limit by accident. Once the
 * ingest queue held nothing but fetching, an import ran into it — and
 * the call that reported it was not the import. A batch that is partly refused
 * re-queues the refused ids at `info` and carries on; what failed out loud was
 * the next "see what is new" sync of the same account, on labels.list, a call
 * that costs one unit. New mail was held up by old mail being fetched too
 * fast.
 *
 * So this keeps a rate rather than reacting to a refusal. Each account has a
 * steady allowance and a small reserve on top of it for a burst; a caller says
 * what it is about to spend, and is made to wait first if that would run ahead
 * of the allowance. The wait is a few seconds at most for one batch, which is
 * why it is a sleep in the handler and not a delayed redelivery: the queue
 * behind it is no worse off than behind any other batch, and nothing has to
 * be re-sent.
 *
 * WELL UNDER the limit on purpose — the worst minute here is 12,000 units of
 * the 15,000. The rest belongs to what is not counted: the person using the
 * mailbox (a send is 100, a label change on a selection 50), push-driven
 * syncs, and whatever else is signed in to the same Google account.
 *
 * IN MEMORY, in this process. That is enough because the bulk fetching is one
 * worker's job — worker-backlog, which runs an import's listing and its
 * batches; worker-ingest also talks to Gmail, but for the handful of calls a
 * "see what is new" sync makes, and those are what the headroom above is left
 * for. It survives from one message to the next because a worker only resets
 * services that ask to be. A restart forgets what was spent and
 * starts with a full reserve, which can overshoot for at most the reserve.
 * Shared storage would close that, at the price of a database round trip per
 * batch to guard against something GmailThrottledException already handles.
 */
final class GmailQuotaPacer
{
    /** What a messages.get costs, and so what each id in a batch costs. */
    public const int UNITS_PER_MESSAGE = 5;

    /** What one page of messages.list costs. */
    public const int UNITS_PER_LIST_PAGE = 5;

    /** The steady allowance: 9,000 units a minute. */
    private const float UNITS_PER_SECOND = 150.0;

    /** How far ahead of the allowance a burst may run: 3,000 units. */
    private const float RESERVE_SECONDS = 20.0;

    /**
     * Per account id, the moment its spending so far is paid off at the
     * steady rate. In the past for an account that has been idle.
     *
     * @var array<int, float>
     */
    private array $paidOffAt = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Announce a spend, and wait here until the account can afford it.
     */
    public function spend(Account $account, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        $key = (int) $account->id;
        $now = (float) $this->clock->now()->format('U.u');

        $paidOffAt = max($this->paidOffAt[$key] ?? 0.0, $now) + $units / self::UNITS_PER_SECOND;

        // Recorded before the wait, not after: what was spent is spent whether
        // or not the sleep is interrupted by the worker being stopped.
        $this->paidOffAt[$key] = $paidOffAt;

        $ahead = $paidOffAt - $now - self::RESERVE_SECONDS;

        if ($ahead > 0.0) {
            $this->clock->sleep($ahead);
        }
    }
}
