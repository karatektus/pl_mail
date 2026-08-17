<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Entity\Mail\Message;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something that can read a fact out of a mail — a parcel, a flight, a pull
 * request — deterministically.
 *
 * The extension seam, and everything hangs off the tag: implementations are
 * collected by InsightExtractorRegistry, run by InsightHarvester after every
 * ingest, and listed BY THE REGISTRY on the settings page, where each can be
 * switched off per user. Adding a kind of insight is one class implementing
 * this — no registry edit, no settings edit, no pipeline edit. That is the
 * "extendable" the feature promises, so keep it true.
 *
 * Deterministic on purpose, like DeterministicDateDetector next door: sender
 * domains, headers, and regular shapes (tracking numbers, flight codes,
 * "#123"). An extractor that guesses puts invented parcels on someone's
 * radar; return nothing rather than probably-something. A model-backed
 * extractor would be one more implementation with a lower priority — the same
 * seam ProposalDetectorInterface documents — and nothing else would change.
 *
 * supports() is the cheap gate (headers, sender), extract() the real work;
 * the harvester never calls extract() without supports() saying yes. Neither
 * may throw for ordinary mail: unparseable is `[]`, not an exception — the
 * pipeline shields the sync either way, but a throwing extractor costs every
 * OTHER extractor its facts for that message.
 */
#[AutoconfigureTag('app.insight_extractor')]
interface InsightExtractorInterface
{
    /**
     * Stable registry id: stored on every row it writes, the settings toggle
     * key, and the dedupe scope. Short kebab-case ("parcel", "github").
     * Renaming it orphans rows and forgets who switched it off.
     */
    public static function key(): string;

    /**
     * Presentation for the settings page, resolved as translation keys
     * `settings.insights.extractor.<key>` / `...<key>_hint` — declared here
     * only through key(); this method names the Font Awesome icon the
     * settings row and the panel's section heading wear.
     */
    public function icon(): string;

    /** Higher runs first; matters only when two claim the same mail. */
    public function priority(): int;

    /** The cheap gate: headers and addresses, no body parsing. */
    public function supports(Message $message): bool;

    /**
     * @return list<InsightDraft> empty is the normal outcome and never an
     *                            error: most mail states no fact worth a card
     */
    public function extract(Message $message): array;
}
