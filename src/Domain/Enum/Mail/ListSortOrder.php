<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

use DateTimeImmutable;
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

        return self::pinnedFirst($qb, $alias)
            ->addOrderBy($alias . '.lastMessageAt', $direction)
            ->addOrderBy($alias . '.id', $direction);
    }

    /**
     * Start the order with the pinned conversations (#654), whichever way the
     * rest of it runs: a pin means "at the top", and with Oldest first the top
     * is where the oldest mail is, which is no reason to put pins at the end.
     *
     * Replaces any order already on the query — it is the first key, so every
     * caller adds its own after it. A HIDDEN select rather than an expression
     * in ORDER BY because most list queries are DISTINCT, and Postgres refuses
     * an ORDER BY expression that is not in the select list of one.
     *
     * The end time is compared here, not only by the sweep that clears it:
     * a pin is over the moment it ends, and the list should not keep one at
     * the top for however long the sweep takes to come round.
     *
     * A static beside applyTo() rather than inside it, for the one list that
     * sorts by something else: Drafts orders by when the draft was written,
     * and its pins go first all the same.
     */
    public static function pinnedFirst(QueryBuilder $qb, string $alias = 't'): QueryBuilder
    {
        return $qb
            ->addSelect(sprintf(
                'CASE WHEN %1$s.pinnedAt IS NOT NULL AND (%1$s.pinnedUntil IS NULL OR %1$s.pinnedUntil > :pinNow) THEN 0 ELSE 1 END AS HIDDEN pinRank',
                $alias,
            ))
            ->setParameter('pinNow', new DateTimeImmutable())
            ->orderBy('pinRank', 'ASC');
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
