<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Mail\Account;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Spaces out the bulk Gmail calls so an import stays inside the account's
 * quota, and finds out what that quota is by being told.
 *
 * Google allows one Gmail user a number of quota units a minute, across
 * everything done in that user's name. Nothing used to count: the worker
 * fetched one batch after another as fast as Google answered, an import ran
 * into the limit — and the call that reported it was not the import but the
 * next "see what is new" sync of the same account, on labels.list, a call that
 * costs one unit. New mail was held up by old mail being fetched too fast.
 *
 * So this keeps a rate. Each account has a steady allowance and a small
 * reserve on top of it for a burst; a caller says what it is about to spend,
 * and is made to wait first if that would run ahead of the allowance. The wait
 * is a few seconds at most for one batch, which is why it is a sleep in the
 * handler and not a delayed redelivery.
 *
 * ## The limit is learnt, not configured
 *
 * The first version of this had the limit written into it: 15,000 units a
 * minute, with a message costing 5. Both were Google's numbers and both
 * stopped being true on 1 May 2026 — a project created since then gets 6,000,
 * and a message costs 20 for everybody. The pacer went on allowing what it
 * believed was 9,000 units a minute and was really 36,000, and an import on a
 * new project was refused for most of what it asked (#90).
 *
 * The limit cannot be asked for: it belongs to the Google Cloud project, and
 * reading it takes a Cloud permission no mailbox grant carries. A setting
 * would be a number every self-hoster has to look up in a console and keep in
 * step with Google. But a refusal says it — the 403 names the limit it was
 * refused under and its value — so the pacer starts from the highest limit
 * there is, is refused once on a project that has less, and keeps to what it
 * was told from then on. A refusal is therefore not a fault here. It is how
 * the number arrives, and everything that calls Gmail in bulk treats it as
 * "not now" rather than as an error: see refused() and waitSeconds().
 *
 * FORGOTTEN AFTER A DAY, on purpose. A limit only ever learnt downwards would
 * hold an install to 6,000 for ever after Google or its administrator raised
 * it. Starting from the top again costs one refused batch a day on a project
 * that has less, and that batch is put back like any other.
 *
 * UNDER the limit — the worst minute here is a little short of all of it. The rest
 * belongs to what is not counted: the person using the mailbox (a send is
 * 100, a label change on a selection 50), push-driven syncs, and whatever
 * else is signed in to the same Google account.
 *
 * ## And the count is not Google's, so the pace gives way too
 *
 * Even at the right limit this application's count is not the one Google
 * keeps. An import that had counted 3,240 units of a minute's 6,000 was
 * refused: the person reading mail, another client signed in to the same
 * account, a cost Google reckons differently — none of it visible from here,
 * and none of it Google's to explain. So a refusal at a limit already known
 * is taken as "slower than this", whatever the count said: the account's
 * pace drops by a quarter, and climbs back a little for every minute that
 * passes without another. It settles just under whatever Google will
 * actually take, and finds its way up again when the other spender stops.
 *
 * That is per account, where the limit is everybody's: who else is spending
 * a mailbox's quota is a fact about that mailbox.
 *
 * ## Shared between processes
 *
 * What an account has spent and what the project's limit is are kept in a
 * cache pool the database backs, not in this object. It was in memory once,
 * on the reasoning that bulk fetching is one worker's job. It is two workers'
 * — worker-backlog fetches an import's batches, worker-ingest fetches the
 * mail arriving meanwhile — and each kept its own count of the same quota.
 * More to the point, a refusal one worker met has to stop the other, and the
 * web request that is about to ask as well. One read and one write per batch
 * is the price, against a batch that is fifty requests to Google.
 *
 * Two workers spending in the same instant can each read the other's stale
 * figure and both go ahead. That overshoots by one batch, inside the fifth
 * left spare, and is not worth a lock.
 */
final class GmailQuotaPacer
{
    /**
     * What fetching one message in full costs, and so what each id in a batch
     * costs.
     *
     * MEASURED, and three times the figure in Google's table. The table gives
     * messages.get one cost, 20. An import paced on that was refused with
     * 3,240 units counted of a 6,000 limit, and no other call was being made.
     * So thirty messages were fetched each way in a minute of their own
     * (app:gmail:quota-probe, 2026-10-13) and the project's quota meter read
     * afterwards:
     *
     *     format=full, batched        1,800 units   60 each
     *     format=full, one at a time  1,800         60
     *     format=raw                  1,800         60
     *     format=metadata               600         20
     *     format=minimal                600         20
     *
     * The table's 20 is the cost of a message without its body. With it, by
     * either format that carries one, it is 60 — and an import needs the body.
     *
     * If Google moves the figure again the pacer does not break: a refusal at
     * a known limit slows the account whatever this says. This is where the
     * pace starts, and what the progress line's figures are reckoned from.
     */
    public const int UNITS_PER_MESSAGE = 60;

    /** What one page of messages.list costs. */
    public const int UNITS_PER_LIST_PAGE = 5;

    /**
     * Units a minute for one user, before Google has said otherwise: what a
     * project that used the API before May 2026 was left with, and the most
     * any project has without asking for more.
     */
    public const int HIGHEST_LIMIT = 15_000;

    /**
     * What a project created since May 2026 has. Fallen back to when a
     * refusal names the per-user limit and leaves out its value.
     */
    public const int CURRENT_DEFAULT_LIMIT = 6_000;

    /**
     * The share of the limit that is the steady allowance.
     *
     * It was three fifths while a message was thought to cost a third of
     * what it does, which in truth was nearly twice the limit. With the cost
     * right, the whole of a 6,000 limit is a hundred messages a minute and
     * there is little to be generous with: this leaves a seventh for the
     * burst below, the syncs and the person using the mailbox, and the pace
     * gives way to a refusal for whatever that does not cover.
     */
    private const float STEADY_SHARE = 0.85;

    /**
     * How far ahead of the allowance a burst may run: five seconds of it,
     * which is one request of five messages and a listing page beside it.
     *
     * It was twenty, a fifth of the limit, on the reading that a limit of so
     * much a minute may be spent however the minute likes. Google does not
     * see it that way — a thousand units in one second was refused on a
     * project allowed six thousand a minute — so the reserve is only what
     * lets a request go out without waiting when the account has been idle.
     */
    private const float RESERVE_SECONDS = 5.0;

    /**
     * How long an account is left alone after a refusal that came with no
     * Retry-After, which is the norm. The limit is counted over a minute, so
     * a minute later the whole of it is back.
     */
    private const int COOLDOWN_SECONDS = 60;

    /** The longest a Retry-After is believed for. */
    private const int LONGEST_COOLDOWN_SECONDS = 900;

    /** How long a learnt limit is kept before the pacer starts from the top again. */
    private const int LIMIT_LIFETIME_SECONDS = 86_400;

    /**
     * The width of one slot of the record of what was spent when. Six of them
     * are the minute Google counts over; see usedThisMinute().
     */
    private const int SLOT_SECONDS = 10;

    /** What a refusal at a known limit leaves of an account's pace. */
    private const float SLOWER = 0.75;

    /** The least an account is ever slowed to: a fifth of its allowance. */
    private const float SLOWEST = 0.2;

    /** How much of its allowance an account wins back per minute without a refusal. */
    private const float FASTER_PER_MINUTE = 0.02;

    private const string LIMIT_KEY = 'gmail_quota.limit';

    private readonly CacheItemPoolInterface $state;

    public function __construct(
        private readonly ClockInterface $clock,
        // Null in the tests that build this by hand and have one process to
        // share with: an array is all the sharing they need.
        #[Autowire(service: 'app.gmail_quota')]
        ?CacheItemPoolInterface $state = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->state = $state ?? new ArrayAdapter();
    }

    /**
     * Announce a spend, and wait here until the account can afford it.
     *
     * Only ever a short wait. An account Google has just refused is not
     * waited on here — that is a minute, and a worker asleep for a minute is
     * every other account's mail not being fetched. The caller asks
     * waitSeconds() first and puts its job back instead.
     */
    public function spend(Account $account, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        $now   = $this->now();
        $item  = $this->state->getItem($this->accountKey($account));
        $state = $this->read($item->get());

        $paidOffAt = max($state['paidOffAt'], $now) + $units / $this->unitsPerSecond($state, $now);

        // Recorded before the wait, not after: what was spent is spent whether
        // or not the sleep is interrupted by the worker being stopped.
        $state['paidOffAt'] = $paidOffAt;

        // Against the moment the call will actually be made, which is after
        // the wait when there is one.
        $slot                  = $this->slot(max($now, $paidOffAt - self::RESERVE_SECONDS));
        $state['spent'][$slot] = ($state['spent'][$slot] ?? 0) + $units;
        $state['spent']        = $this->sinceAMinuteAgo($state['spent'], $now);

        $this->state->save($item->set($state)->expiresAfter(self::LIMIT_LIFETIME_SECONDS));

        $ahead = $paidOffAt - $now - self::RESERVE_SECONDS;

        if ($ahead > 0.0) {
            $this->clock->sleep($ahead);
        }
    }

    /**
     * Google refused a call for this account on quota.
     *
     * Three things follow. The account is left alone until the refusal has
     * had time to clear, by every process — see waitSeconds(). If the refusal
     * was under a per-user limit lower than the one being kept to, the limit
     * it named becomes the one every account is paced to, since it is the
     * project's and not the mailbox's. And if it taught nothing about the
     * limit, this account is paced more slowly: see the class.
     *
     * @param string   $limitName         Google's name for the limit, e.g. "…PerMinutePerUser"; '' when the body had none
     * @param int|null $limitValue        units a minute, when the body said
     * @param int|null $retryAfterSeconds the Retry-After header, when there was one
     */
    public function refused(Account $account, string $limitName = '', ?int $limitValue = null, ?int $retryAfterSeconds = null): void
    {
        $now      = $this->now();
        $cooldown = min(self::LONGEST_COOLDOWN_SECONDS, max(1, $retryAfterSeconds ?? self::COOLDOWN_SECONDS));

        $item  = $this->state->getItem($this->accountKey($account));
        $state = $this->read($item->get());

        // The per-project limit is two hundred times the per-user one and
        // says nothing about how fast a single mailbox may be read.
        $perUser = true === str_contains(strtolower($limitName), 'peruser');
        $current = $this->limit();
        $learnt  = true === $perUser ? ($limitValue ?? self::CURRENT_DEFAULT_LIMIT) : 0;

        // Downwards only. A refusal under a limit higher than the one being
        // kept to is not news about the limit.
        $lowered = $learnt > 0 && $learnt < $current;

        if (true === $lowered) {
            $limit = $this->state->getItem(self::LIMIT_KEY);
            $this->state->save($limit->set($learnt)->expiresAfter(self::LIMIT_LIFETIME_SECONDS));
        }

        // A refusal that arrives while the account is already being left
        // alone is the same one again — a job that was under way, a part of
        // the same batch — and is not a second reason to slow down. Nor is
        // one that has just been explained by the limit being lower than
        // thought.
        $again = $now < $state['refusedUntil'];

        if (false === $lowered && false === $again) {
            $state['pace'] = max(self::SLOWEST, $this->pace($state, $now) * self::SLOWER);
        } else {
            $state['pace'] = $this->pace($state, $now);
        }

        $state['refusedUntil'] = max($state['refusedUntil'], $now + $cooldown);
        // What had been spent when Google said no. Kept because the running
        // count falls to nothing during the wait that follows, and "paused,
        // 0 of 6,000 used" explains nothing; see usedWhenRefused().
        $state['usedWhenRefused'] = array_sum(array_filter(
            $this->sinceAMinuteAgo($state['spent'], $now),
            static fn (int $slot): bool => $slot <= $now,
            ARRAY_FILTER_USE_KEY,
        ));
        // The reserve is gone as well: whatever was thought to be left of this
        // minute, Google has just said there is none.
        $state['paidOffAt'] = max($state['paidOffAt'], $now + self::RESERVE_SECONDS);

        $this->state->save($item->set($state)->expiresAfter(self::LIMIT_LIFETIME_SECONDS));

        if (true === $again) {
            return;
        }

        // The one line that says what Google said and what was made of it.
        // `counted` beside `limit` is how far this application's count is
        // from Google's.
        $this->logger->info('Gmail quota: refused', [
            'accountId'        => $account->id,
            'googleLimitName'  => $limitName,
            'googleLimitValue' => $limitValue,
            'limit'            => $this->limit(),
            'counted'          => $state['usedWhenRefused'],
            'pace'             => round($state['pace'], 2),
            'waitSeconds'      => $cooldown,
        ]);
    }

    /**
     * Leave this account's bulk fetching alone for so many seconds, for a
     * reason that is not Google's: the quota probe, which needs a minute with
     * nothing else in it (see GmailQuotaProbeCommand). 0 ends it.
     *
     * The same wait a refusal causes, so every job that would fetch puts
     * itself back the same way — and none of what a refusal means. The pace
     * is not cut and no limit is learnt, because nothing was refused.
     */
    public function hold(Account $account, int $seconds): void
    {
        $item  = $this->state->getItem($this->accountKey($account));
        $state = $this->read($item->get());

        // Its pace as it stands now, kept: the wait is measured from
        // refusedUntil, and moving that would otherwise change it.
        $now                   = $this->now();
        $state['pace']         = $this->pace($state, $now);
        $state['refusedUntil'] = $now + max(0, $seconds);

        $this->state->save($item->set($state)->expiresAfter(self::LIMIT_LIFETIME_SECONDS));
    }

    /**
     * How long to leave this account alone, in whole seconds; 0 when it may
     * be asked now.
     *
     * What a job asks before it calls Gmail in bulk, and what it delays itself
     * by when the answer is not 0.
     */
    public function waitSeconds(Account $account): int
    {
        $state = $this->read($this->state->getItem($this->accountKey($account))->get());

        return max(0, (int) ceil($state['refusedUntil'] - $this->now()));
    }

    /**
     * Roughly what this account has spent in the minute just gone, in units.
     *
     * AN ESTIMATE, and shown as one. Google does not report what is left of a
     * quota, so this is this application's own count of what it announced to
     * spend() — the bulk fetching, which is nearly all of an import and none
     * of what the person or another client did in the same mailbox. It is
     * also counted in ten-second slots, so it runs up to ten seconds stale.
     */
    public function usedThisMinute(Account $account): int
    {
        $now   = $this->now();
        $state = $this->read($this->state->getItem($this->accountKey($account))->get());

        return array_sum(array_filter(
            $this->sinceAMinuteAgo($state['spent'], $now),
            // A spend that was made to wait is recorded a little ahead, and
            // counts once its moment has come.
            static fn (int $slot): bool => $slot <= $now,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * What this application had counted for the account in the minute before
     * Google last refused it.
     *
     * Shown while the account is paused, and the honest measure of how far
     * the count is from Google's: a refusal at a figure well under the limit
     * is quota spent by something this pacer does not see.
     */
    public function usedWhenRefused(Account $account): int
    {
        return $this->read($this->state->getItem($this->accountKey($account))->get())['usedWhenRefused'];
    }

    /** Whether the limit is one Google named, rather than the one assumed until it does. */
    public function isLimitLearnt(): bool
    {
        $limit = $this->state->getItem(self::LIMIT_KEY)->get();

        return true === is_int($limit) && $limit > 0;
    }

    /** How many messages a minute this account's import fetches at the pace it is being held to. */
    public function messagesPerMinute(Account $account): int
    {
        $state = $this->read($this->state->getItem($this->accountKey($account))->get());

        return max(1, (int) floor($this->unitsPerSecond($state, $this->now()) * 60.0 / self::UNITS_PER_MESSAGE));
    }

    /** Units a minute one user is being held to: Google's last word, or the highest there is. */
    public function limit(): int
    {
        $limit = $this->state->getItem(self::LIMIT_KEY)->get();

        return true === is_int($limit) && $limit > 0 ? $limit : self::HIGHEST_LIMIT;
    }

    /** @param array{pace: float, refusedUntil: float} $state */
    private function unitsPerSecond(array $state, float $now): float
    {
        return $this->limit() * self::STEADY_SHARE * $this->pace($state, $now) / 60.0;
    }

    /**
     * The share of its steady allowance this account is held to just now:
     * what the last refusal left, and a little more for every minute since
     * that refusal stopped applying.
     *
     * @param array{pace: float, refusedUntil: float} $state
     */
    private function pace(array $state, float $now): float
    {
        $minutesSince = max(0.0, $now - $state['refusedUntil']) / 60.0;

        return min(1.0, $state['pace'] + self::FASTER_PER_MINUTE * $minutesSince);
    }

    /**
     * @return array{paidOffAt: float, refusedUntil: float, usedWhenRefused: int, pace: float, spent: array<int, int>}
     *         the moment the account's spending so far is paid off at the
     *         steady rate, and the moment a refusal stops applying — both in
     *         the past for an account that has been idle — and what was spent
     *         in each recent slot, keyed by the second the slot began
     */
    private function read(mixed $stored): array
    {
        $spent = true === is_array($stored) && true === is_array($stored['spent'] ?? null) ? $stored['spent'] : [];

        return [
            'paidOffAt'       => (float) (true === is_array($stored) ? ($stored['paidOffAt'] ?? 0.0) : 0.0),
            'refusedUntil'    => (float) (true === is_array($stored) ? ($stored['refusedUntil'] ?? 0.0) : 0.0),
            'usedWhenRefused' => (int) (true === is_array($stored) ? ($stored['usedWhenRefused'] ?? 0) : 0),
            'pace'            => (float) (true === is_array($stored) ? ($stored['pace'] ?? 1.0) : 1.0),
            'spent'           => array_map(intval(...), $spent),
        ];
    }

    private function slot(float $at): int
    {
        return intdiv((int) $at, self::SLOT_SECONDS) * self::SLOT_SECONDS;
    }

    /**
     * The slots a minute ending now has not yet left behind, those still to
     * come included.
     *
     * @param array<int, int> $spent
     *
     * @return array<int, int>
     */
    private function sinceAMinuteAgo(array $spent, float $now): array
    {
        return array_filter(
            $spent,
            static fn (int $slot): bool => $slot > $now - 60.0,
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function accountKey(Account $account): string
    {
        return 'gmail_quota.account.' . (int) $account->id;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
