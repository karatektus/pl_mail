<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Which queue a batch's follow-up work goes on.
 *
 * The routing table in messenger.yaml sends every post-ingest message to
 * `enrich`, and that is right for a batch that is part of an import. It is
 * wrong for mail that has just arrived on an account whose import is over —
 * that mail may have somebody waiting on it, and `enrich` may be an hour deep
 * in another account's first sync.
 *
 * The message CLASS cannot tell the two apart; the same ClassifyMailMessage is
 * either, depending on the mail it carries. So the choice is made here, where
 * the batch is known, and travels as a stamp.
 *
 * One class rather than a line in each step, because there are five steps and
 * the day a sixth is written it should not be possible to forget.
 */
final readonly class EnrichmentRouter
{
    /** Mail that has just arrived; a worker of its own. */
    public const string LIVE = 'enrich_live';

    /**
     * Recent mail brought in by an import. Reached by the routing table and
     * not by a stamp; named here because the handler at the other end needs
     * to recognise it — see isBulk().
     */
    public const string IMPORT = 'enrich';

    /** Old mail being classified after an import; behind everything else. */
    public const string BACKLOG = 'enrich_backlog';

    /**
     * Whether work taken off this queue is bulk work: an import, or the
     * catch-up after one.
     *
     * The other half of the choice stampsFor() makes. The queue is the one
     * place that still knows, at the far end, which kind of mail a message
     * carried — and so the one place a limit meant only for bulk work can be
     * read from without being able to reach mail that has just arrived.
     */
    public static function isBulk(?string $queue): bool
    {
        return self::IMPORT === $queue || self::BACKLOG === $queue;
    }

    /**
     * The stamps to dispatch a batch's follow-up work with.
     *
     * Empty for an import, which leaves the routing table to decide.
     *
     * @return list<StampInterface>
     */
    public function stampsFor(PostIngestResult $result): array
    {
        return true === $result->live ? self::live() : [];
    }

    /** @return list<StampInterface> */
    public static function live(): array
    {
        return [new TransportNamesStamp([self::LIVE])];
    }

    /** @return list<StampInterface> */
    public static function backlog(): array
    {
        return [new TransportNamesStamp([self::BACKLOG])];
    }
}
