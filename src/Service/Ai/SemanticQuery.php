<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\Enum\Ai\AiCallFeature;
use App\Domain\Enum\Ai\SemanticSkipReason;
use App\Entity\Ai\AiFeature;
use App\Entity\Ai\AiSettings;
use App\Entity\User\User;
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
 * WHEN THERE IS NO VECTOR, THERE IS A REASON
 * ──────────────────────────────────────────
 * This used to answer null for all of it — feature off, no model, host down,
 * query too short, a vector that will not normalise — and the search that
 * followed was the search that has always run. Correct, and silent.
 *
 * Silent is the wrong answer for most of them. An operator switches "search by
 * meaning" on, the box on the shelf is unplugged, and from then on every search
 * quietly answers with less than the person expects: nothing errors, nothing is
 * logged where they can see it, the results are merely disappointing, and the
 * feature gets blamed for a cable. So the reason travels with the absence and
 * the search page prints it.
 *
 * Two of them stay silent, and both are chosen: an installation that has not
 * switched this on has no degraded search to explain, and a query made of
 * nothing but operators was never going to be searched by meaning. See
 * {@see SemanticSkipReason::tellsTheUser()}.
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
        private AiPermissions   $permissions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The vector for one search, or the reason it has none.
     *
     * Never null and never throws: every caller gets an object it can ask both
     * questions of, and the search behind it runs either way.
     *
     * THE USER IS IN THE SIGNATURE, AND MAY BE NULL
     * ─────────────────────────────────────────────
     * Null is what the search page has for a principal it does not recognise —
     * an API token today, a guest later — and it is answered FeatureOff, which
     * is silent. That is right for both halves: an unrecognised principal owns
     * no vectors to match against, and there is nobody to explain it to.
     */
    public function forQuery(?User $user, ?string $freeText): SemanticSearch
    {
        $text     = trim((string) $freeText);
        $settings = $this->ai->settings();

        // Asked BEFORE the length check, which the previous order had the other
        // way round. The reason is not efficiency — it is that "you typed two
        // letters" is the wrong thing to tell somebody on an installation where
        // the feature is switched off entirely. The truer reason wins.
        if (false === $settings->enabledFor(AiFeature::Search)) {
            return SemanticSearch::skipped(self::whyUnavailable($settings));
        }

        // The searcher's own answer, under the installation's. FeatureOff and
        // therefore SILENT, because it is their own decision and a notice under
        // the search box explaining a setting they made themselves is noise —
        // the same reason an install that never switched search on says nothing.
        // See SemanticSkipReason::tellsTheUser().
        if (false === $this->permissions->allows($user, AiFeature::Search)) {
            return SemanticSearch::skipped(SemanticSkipReason::FeatureOff);
        }

        // Operators and nothing else — `is:unread`, `from:alice`. There is no
        // text to embed, which is not the same as text that is too short: one
        // is a query nobody meant to search by meaning and the other is one
        // sentence away from being able to.
        if ('' === $text) {
            return SemanticSearch::skipped(SemanticSkipReason::NoFreeText);
        }

        if (mb_strlen($text) < self::MIN_LENGTH) {
            return SemanticSearch::skipped(SemanticSkipReason::QueryTooShort);
        }

        // THE INSTRUCTION GOES ON, AND ONLY HERE.
        //
        // Embedding models are asymmetric — trained to be told which side of a
        // search they are looking at — and an unlabelled query is treated as
        // one more document. A mailbox is mostly boilerplate, so an unlabelled
        // query lands nearest the most boilerplate thing in it: measured on
        // qwen3-embedding:0.6b, "mails about food" ranked five newsletters and
        // login links above every mail that was actually about food, and
        // "essen" ranked all seven of them above all five. With the model's own
        // instruction, none. Precision went from 0.42 to 1.00.
        //
        // NOT APPLIED TO STORED DOCUMENTS, which is what makes it free to
        // change: MessageEmbedder embeds a message as itself, so nothing in the
        // mailbox has to be re-indexed when this setting moves, and there is no
        // window in which half the vectors were made under one rule. Qwen wants
        // exactly this arrangement. A model that also wants its documents
        // labelled is a different piece of work and EmbeddingPreset says so
        // rather than half-doing it.
        //
        // The LENGTH CHECK ABOVE COUNTS THE PERSON'S WORDS, not this. "Type a
        // little more" has to be about what they typed; measuring the sentence
        // plMail adds would make MIN_LENGTH meaningless the moment an
        // instruction is configured, and every three-letter query would sail
        // past a guard that exists to stop it.
        $result = $this->ai->embedResult(
            AiCallFeature::SearchQuery,
            $settings->searchQueryInstruction . $text,
        );

        if (null === $result->vector) {
            return SemanticSearch::skipped(self::fromErrorKind($result->errorKind));
        }

        $literal = EmbeddingStore::unitLiteral($result->vector);

        if (null === $literal) {
            $this->logger->info('SemanticQuery: the model returned a vector that cannot be normalised', [
                'length' => count($result->vector),
            ]);

            return SemanticSearch::skipped(SemanticSkipReason::ModelAnsweredBadly);
        }

        // The WIDTH IS COUNTED HERE rather than read from settings, and the
        // model name is the one the call was actually made with. Both are bound
        // into the SQL so that the search only ever compares this vector with
        // vectors from the same model at the same width — see SemanticSearch.
        return SemanticSearch::ran(
            $literal,
            (string) $settings->embeddingModel,
            count($result->vector),
            $settings->semanticMinSimilarity,
        );
    }

    /**
     * Off on purpose, or on and unusable.
     *
     * Two states that look identical from enabledFor() and could not be less
     * alike to the person searching: the first is a decision somebody made and
     * needs no explaining, the second is a half-finished setup that will never
     * answer and that nobody is being told about.
     */
    private static function whyUnavailable(AiSettings $settings): SemanticSkipReason
    {
        if (false === $settings->isEnabled || false === $settings->searchEnabled) {
            return SemanticSkipReason::FeatureOff;
        }

        return SemanticSkipReason::NotConfigured;
    }

    /**
     * The host's failure, in words a search page can print.
     *
     * The unmatched kinds — an HTTP status that is not 404, a body that is not
     * a vector, something unforeseen — all land on "answered with something
     * unusable", because that is what they have in common from here and because
     * the specifics belong in the log, not under somebody's search box.
     */
    private static function fromErrorKind(?string $kind): SemanticSkipReason
    {
        return match ($kind) {
            OllamaClient::ERROR_TIMEOUT     => SemanticSkipReason::TimedOut,
            OllamaClient::ERROR_UNREACHABLE => SemanticSkipReason::HostUnreachable,
            OllamaClient::ERROR_HTTP_404    => SemanticSkipReason::ModelMissing,
            AiAssistant::ERROR_DISABLED     => SemanticSkipReason::FeatureOff,
            default                         => SemanticSkipReason::ModelAnsweredBadly,
        };
    }
}
