<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\DTO\Ai\SemanticSearchReport;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * How much of one mailbox the meaning pass could actually see, and what to say
 * about it.
 *
 * WHY THIS IS CACHED AND THE SEARCH IS NOT
 * ────────────────────────────────────────
 * The honest coverage number is a scan of the mailbox — every message of every
 * account, matched against the vectors table — and on the corpus the search
 * comments are measured against (300,000 messages) that is the same order of
 * cost as the search it is a footnote to. Paying it on every keystroke-driven
 * page load would mean this notice cost more than the feature it describes.
 *
 * It is also a number that does not move: a backfill walks a mailbox over
 * minutes and hours, so "4,120 of 48,900" is as true a minute later as it was
 * when it was read. So it is read at most once a minute per person, and the
 * price of that is the only thing this trades away — for up to a minute after a
 * backfill finishes, the notice is still there saying 99%. A minute of a stale
 * progress line against a scan of the mailbox on every search is not a close
 * call.
 *
 * A miss is answered from the database and a broken cache is answered from the
 * database too: nothing here may turn a working search into an error page over
 * a footnote.
 */
final readonly class SemanticCoverage
{
    /**
     * How long a coverage reading is reused.
     *
     * Short enough that somebody watching their own backfill sees it move, long
     * enough that a burst of searches — a query, a sort change, page two, back
     * again — costs one reading between them.
     */
    private const int TTL_SECONDS = 60;

    public function __construct(
        private EmbeddingStore  $embeddings,
        private CacheInterface  $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * What the search page should say about the meaning pass, if anything.
     *
     * @param int $extra results on the page in hand that only the meaning pass found
     */
    public function report(int $userId, SemanticSearch $semantic, int $extra): SemanticSearchReport
    {
        // A pass that never ran has nothing to be complete or incomplete about,
        // and the reason it did not run is already in hand. No scan, no cache
        // read, no work at all for the case where the feature is switched off —
        // which is most installations.
        if (false === $semantic->hasVector()) {
            return new SemanticSearchReport($semantic->skipped, 0, 0, 0, 0);
        }

        $coverage = $this->coverage($userId, (string) $semantic->model, $semantic->dimensions);

        return new SemanticSearchReport(
            null,
            $coverage['embedded'],
            $coverage['eligible'],
            $coverage['stale'],
            $extra,
        );
    }

    /**
     * @return array{embedded: int, eligible: int, stale: int}
     */
    private function coverage(int $userId, string $model, ?int $dimensions): array
    {
        // The model and width are IN THE KEY, not just in the query. Changing
        // the search model changes what "embedded" counts, and a key that did
        // not say so would answer the first minute after the change with the
        // old model's number — which is exactly the minute somebody is looking
        // at the search to find out what the change did.
        $key = sprintf('semantic_coverage_%d_%s_%d', $userId, sha1($model), $dimensions ?? 0);

        try {
            /** @var array{embedded: int, eligible: int, stale: int} $coverage */
            $coverage = $this->cache->get($key, function (ItemInterface $item) use ($userId, $model, $dimensions): array {
                $item->expiresAfter(self::TTL_SECONDS);

                return $this->embeddings->coverageDetailFor($userId, $model, $dimensions);
            });

            return $coverage;
        } catch (Throwable $exception) {
            // A cache that cannot be written — a read-only var/, a pool
            // misconfigured — must not take the search page with it.
            $this->logger->warning('SemanticCoverage: could not read the cached coverage', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return $this->embeddings->coverageDetailFor($userId, $model, $dimensions);
        }
    }
}
