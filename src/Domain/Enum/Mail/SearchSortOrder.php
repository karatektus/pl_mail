<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What order search results come back in.
 *
 * Search used to have one order — `ts_rank` descending — and no way to say
 * otherwise. That is defensible for a search engine and wrong for a mailbox:
 * ranked results interleave a mail from 2004 between two from 2026, and the
 * person reading them was looking for the recent one. Every mail client people
 * already know answers newest-first and offers relevance as the alternative, so
 * that is what {@see self::Recent} being the default means here.
 *
 * Relevance is still the better answer for a keyword nobody remembers the date
 * of, which is why it stayed rather than being removed.
 */
enum SearchSortOrder: string
{
    /** Newest first. The default, and what "search my mail" usually means. */
    case Recent = 'recent';

    /** Best full-text match first — the order search shipped with. */
    case Relevance = 'relevance';

    /**
     * The ORDER BY for {@see \App\Repository\Mail\MessageThreadRepository}, over
     * the aliases its search projection exposes.
     *
     * Both orders end in `thread_id DESC`, and that is not decoration. LIMIT /
     * OFFSET pagination over a non-deterministic sort is free to return the same
     * row on two pages and no row for some third — Postgres may pick a different
     * plan per page, and nothing in the query forbids it from breaking a tie
     * differently the second time. Relevance ties are not an edge case either:
     * `ts_rank` is degenerate for a query that stems to nothing, so every row
     * scores the same and the entire ordering rests on the tiebreaker.
     *
     * ## Words before vectors, whichever order is chosen
     *
     * `semantic_only ASC` leads both, and it is the fix for a search that put
     * the best match in the mailbox on page two. A semantic hit can only be
     * drawn from the most recent SEMANTIC_CANDIDATES messages, so under
     * `Recent` every one of them is structurally newer than most of the mail it
     * is competing with — they do not merely tend to win, they are guaranteed
     * to. Forty-seven of them once sat above a reply whose subject AND sender
     * both contained the search term, purely because it was six weeks older.
     *
     * It fixes `Relevance` as well, and there the disease was worse. `rank` is
     * `GREATEST(ts_rank, cosine_similarity)`, which mixes two scales that have
     * no common unit: measured against this schema's weights, a subject match
     * scores 0.6079, a body match 0.1216, a token-part match 0.0608 — while any
     * admitted semantic hit is ≥ 0.42 by definition. So sorting by relevance
     * put every barely-admissible vector hit above every body match, and any
     * vector hit over 0.608 above an exact subject match. Leading with
     * provenance means `rank` is now only ever compared within one scale:
     * ts_rank against ts_rank among the rows words found, similarity against
     * similarity among the rows only the vector did. The two never meet.
     *
     * This is a presentation rule and not a filter. Nothing is removed — the
     * meaning matches are all still there, badged, in a block at the end.
     */
    public function orderBy(): string
    {
        return match ($this) {
            self::Recent    => 'semantic_only ASC, last_message_at DESC, thread_id DESC',
            self::Relevance => 'semantic_only ASC, rank DESC, last_message_at DESC, thread_id DESC',
        };
    }

    /** Translation key for the option's label in the sort menu. */
    public function transKey(): string
    {
        return 'search.sort.' . $this->value;
    }

    /**
     * Whatever was stored or typed, read charitably.
     *
     * The settings bag is untyped and the query string is whatever somebody
     * put there, so an unrecognised value falls back rather than throwing: a
     * hand-edited URL should show results in some order, not a 500.
     */
    public static function fromSetting(mixed $value, self $default = self::Recent): self
    {
        return true === is_string($value) ? self::tryFrom($value) ?? $default : $default;
    }
}
