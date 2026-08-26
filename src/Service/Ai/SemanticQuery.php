<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Ai\AiFeature;
use Psr\Log\LoggerInterface;

/**
 * Turns what somebody typed into the vector the search binds, once per search.
 *
 * ONCE IS THE ENTIRE POINT
 * ────────────────────────
 * buildSearchSql() runs up to four times for a single search — the cheap pass,
 * the body rescue, and twice more when a page past the end has to recover its
 * total — and one of those runs inside a transaction with a statement timeout.
 * Embedding inside it would put a round trip to another machine in all four,
 * and would put one inside the timeout, where a slow model would be reported as
 * a database problem.
 *
 * So the controller asks here exactly once and hands the answer down.
 *
 * WHEN IT ANSWERS NULL
 * ────────────────────
 * Feature off, no model, host unreachable, a query too short to mean anything,
 * or a vector that cannot be normalised. Every one of them gives null, and null
 * means the search runs exactly as it always has — the semantic arm is simply
 * not added. There is no degraded mode and nothing to explain to the person
 * searching.
 */
final readonly class SemanticQuery
{
    /**
     * Below this, a query is not worth a round trip.
     *
     * Short strings embed to something, but that something is dominated by
     * whatever the model does with fragments — the results are noise wearing
     * the costume of a semantic match, and they arrive several hundred
     * milliseconds late. Lexical search is better at short queries anyway,
     * which is the case this cedes to it.
     */
    private const int MIN_LENGTH = 4;

    public function __construct(
        private AiAssistant     $ai,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null a PostgreSQL `real[]` literal, unit length
     */
    public function literalFor(?string $freeText): ?string
    {
        $text = trim((string) $freeText);

        if (mb_strlen($text) < self::MIN_LENGTH) {
            return null;
        }

        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            return null;
        }

        $vector = $this->ai->embed($text);

        if (null === $vector) {
            // The host is down or the model is gone. The search still works;
            // it is just the search it has always been.
            return null;
        }

        $literal = EmbeddingStore::unitLiteral($vector);

        if (null === $literal) {
            $this->logger->info('SemanticQuery: the model returned a vector that cannot be normalised', [
                'length' => count($vector),
            ]);
        }

        return $literal;
    }
}
