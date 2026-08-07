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
     */
    public function orderBy(): string
    {
        return match ($this) {
            self::Recent    => 'last_message_at DESC, thread_id DESC',
            self::Relevance => 'rank DESC, last_message_at DESC, thread_id DESC',
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
