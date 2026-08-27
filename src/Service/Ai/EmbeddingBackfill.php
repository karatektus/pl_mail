<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\BackfillProgress;
use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Domain\Enum\Ai\BackfillStatus;
use App\Entity\Ai\AiFeature;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\BackfillEmbeddingsMessage;
use App\Repository\Ai\AiBackfillStateRepository;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Start, pause, resume — and the honest answer to "how far has it got".
 *
 * The buttons in the admin panel and the numbers beside them, in one place, so
 * that what the panel offers and what the queue will actually accept cannot
 * drift apart. Every control returns a KEY rather than a boolean: "already
 * running", "search is off" and "there is nothing to resume" are three refusals
 * with three different answers, and a false would collapse them into a shrug.
 *
 * PROGRESS IS COUNTED, NEVER ACCUMULATED
 * ──────────────────────────────────────
 * The percentage is embeddings that exist against messages that could have one,
 * both queried at the moment they are asked for. Nothing increments a stored
 * counter, so a worker killed mid-chunk, a container restart, or a backfill
 * that ran last week and was forgotten all leave the number correct — the
 * vectors ARE the record. It is also why "resume" is cheap: the walk skips what
 * is already there rather than trusting a position.
 *
 * THE RATE IS A MEASUREMENT AND THE ETA IS A CLAIM
 * ────────────────────────────────────────────────
 * Rows written in the last five minutes, divided by five minutes. That is a
 * fact. Turning it into "about two hours left" assumes the next two hours look
 * like the last five minutes, which is a strong assumption for a job that
 * deliberately stops whenever somebody uses the composer — so the estimate is
 * withheld until the recent rate and the window rate agree, and the panel says
 * plainly that it is an estimate when it does appear.
 */
final readonly class EmbeddingBackfill
{
    public const string STARTED          = 'started';
    public const string PAUSED           = 'paused';
    public const string RESUMED          = 'resumed';
    public const string ALREADY_RUNNING  = 'already_running';
    public const string NOT_RUNNING      = 'not_running';
    public const string RESUMES_ITSELF   = 'resumes_itself';
    public const string NOTHING_TO_RESUME = 'nothing_to_resume';
    public const string SEARCH_OFF       = 'search_off';
    public const string NO_MAILBOXES     = 'no_mailboxes';

    /** The window the headline rate is measured over. */
    private const int RATE_WINDOW_SECONDS = 300;

    /** The shorter window it is checked against before an ETA is shown. */
    private const int RATE_RECENT_SECONDS = 60;

    /**
     * Vectors the window must hold before the rate is worth dividing.
     *
     * Sixty in five minutes is a fifth of a message a second — far below what
     * even a yielding backfill manages, so this excludes the first few seconds
     * of a run and nothing else.
     */
    private const int RATE_MIN_SAMPLES = 60;

    /** How long a run must have been going before its rate describes it. */
    private const int RATE_MIN_AGE_SECONDS = 120;

    /**
     * How far the one-minute rate may differ from the five-minute one.
     *
     * Half to double. Wider than it sounds for a job that stops dead every time
     * somebody clicks in the composer — and still narrow enough to withhold the
     * estimate during the first minutes, when the two disagree by an order of
     * magnitude.
     */
    private const float RATE_STABLE_FACTOR = 2.0;

    /** Past this an estimate is arithmetic, not information. */
    private const int ETA_CEILING_SECONDS = 30 * 24 * 3600;

    public function __construct(
        private AiBackfillStateRepository $state,
        private AiSettingsRepository      $settings,
        private AiAssistant               $ai,
        private AiPermissions             $permissions,
        private EmbeddingStore            $store,
        private UserRepository            $users,
        private MessageBusInterface       $bus,
        private BackfillPolicy            $policy,
        private LoggerInterface           $logger,
    ) {
    }

    /**
     * Walk the named mailboxes from the beginning — or all of them.
     *
     * From the beginning rather than from wherever the last run stopped, and
     * that is not wasteful: a chunk of ids already embedded costs one indexed
     * query and no model call at all. Starting over is also the only way to
     * pick up mail that arrived below the old cursor — after a model change,
     * that is the entire mailbox.
     *
     * THE ONLY WAY IN, INCLUDING FROM THE CONSOLE
     * ───────────────────────────────────────────
     * app:ai:embed-mailbox comes through here rather than dispatching the
     * message itself. It has to: the handler stops on the first delivery
     * unless the state row says a run is meant to be going, so a command that
     * posted its own message would queue a job that returned immediately and
     * report success — the exact "it says it started and nothing happens"
     * failure this whole panel was built to end.
     *
     * @param list<int> $userIds empty for every mailbox on the installation
     */
    public function start(array $userIds = []): string
    {
        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            return self::SEARCH_OFF;
        }

        $model = (string) $this->settings->currentOrDefault()->embeddingModel;

        if ([] === $userIds) {
            foreach ($this->users->findAll() as $user) {
                if (null !== $user->id) {
                    $userIds[] = (int) $user->id;
                }
            }
        }

        // Mailboxes whose owner has switched search off come out of the LIST,
        // not out of the walk. The handler refuses them anyway, so this is not
        // what makes the opt-out work — it is what stops the panel counting a
        // mailbox towards a run that will skip it, which reads as a backfill
        // that never quite finishes.
        $userIds = $this->allowing($userIds);

        if ([] === $userIds) {
            return self::NO_MAILBOXES;
        }

        $now = new DateTimeImmutable();

        // The claim is the guard. Two administrators pressing Start together
        // both get here; only one of them gets the row.
        if (false === $this->state->begin($model, $userIds, $now)) {
            return self::ALREADY_RUNNING;
        }

        foreach ($userIds as $userId) {
            $this->bus->dispatch(new BackfillEmbeddingsMessage($userId));
        }

        $this->logger->info('EmbeddingBackfill: started', ['mailboxes' => count($userIds), 'model' => $model]);

        return self::STARTED;
    }

    /**
     * Stop after the chunk that is in flight.
     *
     * There is no way to stop one sooner, and no reason to want one: a chunk is
     * a handful of messages and a few seconds, and the state is written before
     * the next delivery is posted — so the walk stops at the next boundary
     * rather than being killed in the middle of a message.
     */
    public function pause(): string
    {
        if (false === $this->state->current()->isLive()) {
            return self::NOT_RUNNING;
        }

        $this->state->pause(BackfillPauseReason::Operator, new DateTimeImmutable());

        return self::PAUSED;
    }

    /** Pick the walk back up where each mailbox's cursor left it. */
    public function resume(): string
    {
        $run    = $this->state->current();
        $now    = new DateTimeImmutable();
        $stalled = self::isStalled($run->isLive(), $run->lastProgressAt, $now);

        // A pause that lifts on its own already has a delivery in the queue.
        // Dispatching another would double the chains over one mailbox, which
        // is the opposite of what somebody pressing Resume wants.
        if (true === $run->isLive() && true === ($run->pauseReason?->resumesItself() ?? false) && false === $stalled) {
            return self::RESUMES_ITSELF;
        }

        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            return self::SEARCH_OFF;
        }

        // Filtered for the same reason start()'s list is: somebody may have
        // switched their own search off since the run began, and resuming a
        // chain over their mailbox would post a delivery whose only outcome is
        // the handler recording it finished.
        $pending = $this->allowing($run->unfinishedUserIds());

        if ([] === $pending) {
            return self::NOTHING_TO_RESUME;
        }

        if (false === $this->state->resume($now)) {
            return self::NOTHING_TO_RESUME;
        }

        foreach ($pending as $userId) {
            $this->bus->dispatch(new BackfillEmbeddingsMessage($userId, $run->cursorFor($userId)));
        }

        return self::RESUMED;
    }

    /** Everything the panel says about the backfill. */
    public function progress(): BackfillProgress
    {
        $run       = $this->state->current();
        $settings  = $this->settings->currentOrDefault();
        $now       = new DateTimeImmutable();
        $searchOn  = $settings->enabledFor(AiFeature::Search);

        // The run's own model, falling back to the configured one. They differ
        // exactly when somebody changed the model after a run — and then the
        // stored vectors are the OLD model's, which is why the coverage is
        // counted against what is configured now: it is the number that says
        // "none of this mailbox is searchable any more", which is the truth.
        $model = null === $settings->embeddingModel || '' === trim((string) $settings->embeddingModel)
            ? $run->model
            : (string) $settings->embeddingModel;

        $coverage = null === $model
            ? ['embedded' => 0, 'eligible' => 0]
            : $this->store->coverage($model);

        $live    = $run->isLive();
        $stalled = self::isStalled($live, $run->lastProgressAt, $now);

        $rate = null;
        $eta  = null;

        if (null !== $model && true === $live && false === $stalled) {
            [$rate, $eta] = $this->measure($model, $run->startedAt, max(0, $coverage['eligible'] - $coverage['embedded']), $now);
        }

        return new BackfillProgress(
            status:          $run->status,
            pauseReason:     $run->pauseReason,
            model:           $model,
            embedded:        $coverage['embedded'],
            eligible:        $coverage['eligible'],
            failures:        $run->failures,
            ratePerSecond:   $rate,
            etaSeconds:      $eta,
            startedAt:       $run->startedAt,
            lastProgressAt:  $run->lastProgressAt,
            finishedAt:      $run->finishedAt,
            stalled:         $stalled,
            canStart:        $searchOn && (false === $live || $stalled),
            canPause:        $live && false === $stalled,
            canResume:       $searchOn && [] !== $run->unfinishedUserIds() && (
                BackfillStatus::Failed === $run->status
                || $stalled
                || (BackfillStatus::Paused === $run->status && false === ($run->pauseReason?->resumesItself() ?? false))
            ),
            blockedReason:   $searchOn ? null : self::SEARCH_OFF,
            batchSize:       $this->policy->batchSize,
            pauseMs:         $this->policy->pauseMs,
            cooldownSeconds: $this->policy->cooldownSeconds,
        );
    }

    /**
     * The mailboxes on that list whose owners still allow indexing.
     *
     * By id, and one find() each, which is a primary-key lookup Doctrine
     * answers from the identity map after the first — this runs once per Start
     * or Resume, not per chunk. A user id that no longer resolves is dropped
     * rather than kept: a run cannot walk a mailbox that is not there, and
     * keeping it would leave the run unable to reach "complete".
     *
     * @param list<int> $userIds
     *
     * @return list<int>
     */
    private function allowing(array $userIds): array
    {
        $allowed = [];

        foreach ($userIds as $userId) {
            $user = $this->users->find($userId);

            if (false === $user instanceof User) {
                continue;
            }

            if (true === $this->permissions->allows($user, AiFeature::Search)) {
                $allowed[] = $userId;
            }
        }

        return $allowed;
    }

    /**
     * Messages a second, and how long that leaves — when it means anything.
     *
     * @return array{0: float|null, 1: int|null}
     */
    private function measure(string $model, ?DateTimeImmutable $startedAt, int $remaining, DateTimeImmutable $now): array
    {
        $windowCount = $this->store->storedSince($model, $now->modify('-' . self::RATE_WINDOW_SECONDS . ' seconds'));
        $recentCount = $this->store->storedSince($model, $now->modify('-' . self::RATE_RECENT_SECONDS . ' seconds'));

        $windowRate = $windowCount / self::RATE_WINDOW_SECONDS;
        $recentRate = $recentCount / self::RATE_RECENT_SECONDS;

        if ($windowCount <= 0) {
            // Running and storing nothing: yielding to the composer, or waiting
            // on a host. Zero is the honest rate, and no estimate follows it.
            return [0.0, null];
        }

        $rate = round($windowRate, 2);

        $young = null === $startedAt
            || $startedAt->getTimestamp() > $now->getTimestamp() - self::RATE_MIN_AGE_SECONDS;

        $unsteady = $recentRate > $windowRate * self::RATE_STABLE_FACTOR
            || $recentRate < $windowRate / self::RATE_STABLE_FACTOR;

        if ($windowCount < self::RATE_MIN_SAMPLES || true === $young || true === $unsteady || $remaining <= 0) {
            return [$rate, null];
        }

        $eta = (int) ceil($remaining / $windowRate);

        return [$rate, $eta > self::ETA_CEILING_SECONDS ? null : $eta];
    }

    /**
     * A run the queue still believes in, that has not moved in a long time.
     *
     * The worker was killed, or its delivery was lost. Named rather than
     * hidden, because the row still says "running" and a panel repeating that
     * for an hour is the one thing an operator cannot argue with — and because
     * it is what re-opens Start and Resume when nothing else would.
     */
    private static function isStalled(bool $live, ?DateTimeImmutable $lastProgressAt, DateTimeImmutable $now): bool
    {
        if (false === $live || null === $lastProgressAt) {
            return false;
        }

        return $lastProgressAt->getTimestamp() < $now->getTimestamp() - AiBackfillStateRepository::STALE_AFTER_SECONDS;
    }
}
