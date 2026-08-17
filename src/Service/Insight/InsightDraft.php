<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Domain\Enum\Insight\InsightKind;

/**
 * What an extractor hands back: the fact, before it is a row.
 *
 * A DTO rather than a MailInsight so extractors can be tested as pure
 * functions of a Message — no entity manager, no account wiring, no
 * lifecycle. The harvester owns turning drafts into rows and is the only
 * place upsert semantics live.
 */
final readonly class InsightDraft
{
    /**
     * @param string               $dedupeKey identity of the THING (tracking number,
     *                                        flight+date, repo#number) — never of the mail.
     *                                        The harvester scopes it by extractor key, so
     *                                        extractors need not name themselves in it.
     * @param array<string, mixed> $payload   per-kind card detail; keep it JSON-safe scalars
     */
    public function __construct(
        public InsightKind $kind,
        public string $title,
        public string $dedupeKey,
        public array $payload = [],
        public ?\DateTimeImmutable $happensAt = null,
    ) {
    }
}
