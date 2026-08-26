<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

use App\Entity\Ai\AiFeature;

/**
 * What a model call was FOR, as recorded.
 *
 * Deliberately not AiFeature. That enum answers a different question — "is this
 * allowed, and with which model" — and its three cases are matched
 * exhaustively in AiSettings::enabledFor(). A fourth case there is an
 * UnhandledMatchError on the settings page, which is a strange price to pay for
 * a metric.
 *
 * The split AiFeature cannot make is Search. One search-box query is four
 * characters of interactive latency that somebody is sitting and waiting on;
 * one mail body is two thousand characters, written unattended, a hundred
 * thousand times over, during a backfill. Averaged together the second buries
 * the first entirely and the panel ends up reporting the backfill's throughput
 * under the heading "search" — which is the opposite of what the panel is for.
 */
enum AiCallFeature: string
{
    /** One interactive query, on the request path. SemanticQuery::forQuery(). */
    case SearchQuery = 'search_query';

    /** One mail body, unattended and in bulk. MessageEmbedder. */
    case MailIndex = 'mail_index';

    /** Deciding which tab a message belongs in. ClassifyMailHandler. */
    case Categorise = 'categorise';

    /** Drafting help in the composer. WritingAssistant. */
    case WritingHelp = 'writing_help';

    /** Which capability gate this workload sits behind. */
    public function gate(): AiFeature
    {
        return match ($this) {
            self::SearchQuery,
            self::MailIndex   => AiFeature::Search,
            self::Categorise  => AiFeature::Categorise,
            self::WritingHelp => AiFeature::WritingHelp,
        };
    }

    /**
     * The tag for a chat call, derived from the gate it already carries.
     *
     * All three arms are spelled out rather than defaulted. chat() refuses
     * Search before this is ever reached, and a silent default here would let a
     * future AiFeature case quietly record as the wrong workload instead of
     * failing where somebody would notice.
     */
    public static function forChat(AiFeature $feature): self
    {
        return match ($feature) {
            AiFeature::Categorise  => self::Categorise,
            AiFeature::WritingHelp => self::WritingHelp,
            AiFeature::Search      => self::SearchQuery,
        };
    }
}
