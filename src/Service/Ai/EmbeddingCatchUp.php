<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\SemanticSearch;
use App\Entity\Ai\AiFeature;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\EmbedMessagesMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Queues the mail that arrived since the last time anybody looked, a bounded
 * handful at a time.
 *
 * WHY NEW MAIL IS NOT INDEXED THE MOMENT IT LANDS
 * ───────────────────────────────────────────────
 * It used to be: a post-ingest step dispatched an EmbedMessagesMessage for
 * every arriving batch, so every message on the installation paid a round trip
 * to the model host within seconds of arriving. That is a lot of GPU spent
 * against a question nobody has asked — searching for mail that arrived
 * moments ago is the rarest thing anyone does with a mail client, because they
 * have just read it. So the policy is now the two moments when indexing is
 * actually cheap or actually needed:
 *
 *  · ONCE A NIGHT, as the backstop — sweep(), from app:ai:index-new-mail.
 *  · RIGHT AFTER A SEARCH, because the search has just paid the cold load on
 *    the embedding model and it is sitting warm — afterSearch().
 *
 * The second is the interesting one and it is the reason the yielding signal
 * was narrowed: see InteractiveAiActivitySubscriber. plMail runs two models
 * with nothing in common — the composer's is 20.3 GiB and thirteen seconds
 * cold, and this one is well under a gigabyte and a couple of seconds — and
 * search and indexing share the small one. A finished search is therefore an
 * invitation to index rather than a reason to stand aside.
 *
 * WHAT THIS IS NOT
 * ────────────────
 * It is not a backfill. EmbeddingBackfill does whole-mailbox work: it claims a
 * state row, walks forwards by id for hours, resumes, reports progress and
 * stands aside for the composer. This has a budget per call, keeps no state,
 * reports nothing, and takes the NEWEST outstanding mail rather than the
 * oldest. The two must not become each other — an unbounded version of this
 * would be a second backfill with no pause button, racing the real one for the
 * same host.
 *
 * IT DISPATCHES, IT DOES NOT EMBED
 * ────────────────────────────────
 * Both entry points end in EmbedMessagesMessage, the same message the ingest
 * step used to post and the same handler that has always consumed it — which
 * already re-checks the feature, already skips what is stored under the current
 * model, and is already routed to a transport with a worker of its own. A
 * second path that embedded inline would have to re-implement every one of
 * those, and one of them would be re-implemented wrongly.
 */
final readonly class EmbeddingCatchUp
{
    /**
     * How many messages one search may queue.
     *
     * Small on purpose. This is a top-up in a warm window, not a catch-up for a
     * mailbox that has been ignored for a month — that is what the nightly
     * sweep and, failing that, app:ai:embed-mailbox are for. Fifty is a few
     * seconds of a warm embedding model, and somebody who searches twice a day
     * still keeps ahead of an ordinary inbox on it.
     */
    public const int AFTER_SEARCH_LIMIT = 50;

    /**
     * How long one mailbox stays quiet after a search has queued a batch.
     *
     * Without it, paging through results queues a batch per page and typing in
     * the search box queues one per keystroke that reaches the server — the
     * same fifty ids over and over, because nothing has been embedded yet when
     * the next one is asked. Five minutes is comfortably longer than one
     * person's session with one query and far shorter than the gap between
     * their sessions, so the useful case — search now, search again after
     * lunch — still gets both windows.
     */
    private const int QUIET_SECONDS = 300;

    public function __construct(
        private MessageRepository      $messages,
        private AiPermissions          $permissions,
        private AiSettingsRepository   $settings,
        private BackfillPolicy         $policy,
        private MessageBusInterface    $bus,
        private CacheItemPoolInterface $cache,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * The nightly backstop for one mailbox.
     *
     * Unthrottled, because its rate limit is the schedule it runs on, and
     * bounded by $limit, because a sweep with no ceiling is a backfill that
     * nobody can pause.
     *
     * @return int how many messages were queued
     */
    public function sweep(User $user, int $limit): int
    {
        return $this->queue($user, $limit);
    }

    /**
     * The opportunistic half, called once a search has actually embedded a
     * query.
     *
     * Takes the whole SemanticSearch rather than a boolean, because
     * hasVector() is the only honest way to know the model answered: the
     * feature can be on with a host that is unplugged, and queueing indexing
     * against a host that has just refused a four-word query would be fifty
     * failures in a row on the ingest queue.
     *
     * That check also covers the switches this has to respect — SemanticQuery
     * refuses before it embeds unless the master switch, the search feature and
     * this person's own answer all say yes — and queue() asks again anyway,
     * because settings change between one request and the next.
     *
     * WHICH MEANS ONE SETTING WITH ONE MEANING, AND THAT IS DELIBERATE.
     * Somebody who has switched search off stops being indexed as well as
     * stopping being able to search, because the two are the same feature seen
     * from its two ends; indexing a mailbox nobody can search would be work
     * nothing ever reads.
     *
     * @return int how many messages were queued
     */
    public function afterSearch(?User $user, SemanticSearch $semantic): int
    {
        // A null user is the search page's answer for a principal it does not
        // recognise — an API token today, a guest later. It owns no mail, and
        // it is refused before the throttle rather than after, so an
        // unrecognised principal cannot take the quiet window away from a real
        // one that shares the key.
        $userId = null === $user ? 0 : (int) $user->id;

        if (null === $user || $userId <= 0 || false === $semantic->hasVector()) {
            return 0;
        }

        if (false === $this->claimQuietWindow($userId)) {
            return 0;
        }

        return $this->queue($user, self::AFTER_SEARCH_LIMIT);
    }

    /**
     * Find the outstanding mail and post it, in chunks the worker can finish.
     *
     * CHUNKED BY BackfillPolicy::$batchSize, which is not laziness about
     * picking a number: that is the tuned answer to "how long may one embedding
     * job hold the host before something else gets a turn", it is already
     * settable per deployment, and a sweep that posted five hundred ids in one
     * envelope would occupy the ingest worker for minutes while mail arrived
     * behind it.
     */
    private function queue(User $user, int $limit): int
    {
        $userId = (int) $user->id;

        if ($userId <= 0 || $limit <= 0) {
            return 0;
        }

        // The installation's switches and this person's together — a mailbox on
        // an installation that has switched search off has nothing to catch up
        // on, and neither has one whose owner has. See AiPermissions.
        if (false === $this->permissions->allows($user, AiFeature::Search)) {
            return 0;
        }

        $model = (string) $this->settings->currentOrDefault()->embeddingModel;

        $ids = $this->messages->unembeddedIdsForUser($userId, $model, $limit);

        if ([] === $ids) {
            return 0;
        }

        // The id travels WITH the batch. The handler is a different process
        // reading a row off a transport, and without it there is nothing there
        // to say whose mail it is holding — so it would have to guess, which is
        // the one thing a worker over somebody's mail must not do.
        foreach (array_chunk($ids, $this->policy->batchSize) as $chunk) {
            $this->bus->dispatch(new EmbedMessagesMessage(array_values($chunk), $userId));
        }

        $this->logger->info('EmbeddingCatchUp: queued mail that had not been indexed', [
            'userId'   => $userId,
            'messages' => count($ids),
            'model'    => $model,
        ]);

        return count($ids);
    }

    /**
     * Take the window for this mailbox, or report that somebody already has it.
     *
     * The marker is written BEFORE the query rather than after the dispatch, so
     * the thing being rate-limited is the whole of it — the mailbox scan
     * included. That scan is bounded and cheaper than the coverage count the
     * same request already pays for, but it is not free, and a search page that
     * paid it per keystroke would be the mistake SemanticCoverage's own docblock
     * spends four paragraphs avoiding.
     *
     * cache.app, which is per container rather than shared. The worst that
     * costs is one extra batch per web container per window, and the handler
     * skips whatever is already stored — against the alternative of putting a
     * write to the database in front of a search to save it.
     *
     * A cache that cannot be read or written answers "go ahead". The failure
     * this protects against is a busy person queueing the same fifty ids twice;
     * the failure it must never cause is a broken cache pool taking the search
     * page down.
     */
    private function claimQuietWindow(int $userId): bool
    {
        try {
            $item = $this->cache->getItem('ai.embedding_catch_up.' . $userId);

            if (true === $item->isHit()) {
                return false;
            }

            $this->cache->save($item->set(true)->expiresAfter(self::QUIET_SECONDS));

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning('EmbeddingCatchUp: could not read the throttle', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return true;
        }
    }
}
