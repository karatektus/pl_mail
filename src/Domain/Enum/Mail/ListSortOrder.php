<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

use Doctrine\ORM\QueryBuilder;

/**
 * What order a folder list comes back in.
 *
 * Separate from {@see SearchSortOrder} rather than shared with it, because
 * relevance is not an order a folder can be in: there is no query to be
 * relevant to, and an enum offering it here would put a dead option in every
 * menu. What the two share is the tiebreaker rule, and the reason for it —
 * see SearchSortOrder::orderBy(): LIMIT/OFFSET over a non-deterministic sort
 * may show the same conversation on two pages and some other on none, and
 * `lastMessageAt` ties are ordinary in a mailbox, not an edge case (an import,
 * a bulk sync, anything that lands a batch with one timestamp).
 */
enum ListSortOrder: string
{
    /** Newest first — what every mail client does, and the default. */
    case Newest = 'newest';

    /** Oldest first, for reading a backlog forwards. */
    case Oldest = 'oldest';

    /**
     * Apply this order to a thread query built over the alias `t`.
     *
     * A method on the enum rather than an ORDER BY spelled at each call site,
     * for the reason the six role views became one controller method: the
     * tiebreaker has to accompany the sort everywhere or it protects nothing,
     * and six copies is six chances to write only the first half.
     */
    public function applyTo(QueryBuilder $qb, string $alias = 't'): QueryBuilder
    {
        $direction = self::Oldest === $this ? 'ASC' : 'DESC';

        return $qb
            ->orderBy($alias . '.lastMessageAt', $direction)
            ->addOrderBy($alias . '.id', $direction);
    }

    /** Translation key for this option's label in the sort menu. */
    public function transKey(): string
    {
        return 'list.sort.' . $this->value;
    }

    /**
     * Whatever was stored or typed, read charitably — the query string is
     * whatever somebody put there, and a hand-edited URL should show a list in
     * some order rather than a 500.
     */
    public static function fromSetting(mixed $value, self $default = self::Newest): self
    {
        return is_string($value) ? self::tryFrom($value) ?? $default : $default;
    }
}
