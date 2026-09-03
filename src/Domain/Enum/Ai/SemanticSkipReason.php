<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

/**
 * Why a search did not get a vector.
 *
 * WHY THESE ARE NOT ALL THE SAME NULL ANY MORE
 * ────────────────────────────────────────────
 * SemanticQuery used to answer null for every one of them, and the search that
 * followed was the search that has always run — correct, and completely silent.
 * That silence is fine while the feature is off, and misleading while it is on:
 * an operator switches "search by meaning" on, their model host is unplugged,
 * and every search from then on quietly answers with fewer results than the
 * person expects. Nothing is broken enough to notice; the results are merely
 * disappointing, and the feature gets the blame for a switch nobody can see.
 *
 * So a skipped pass carries its reason, and the search page says it — except
 * for the one case where there is nothing to say. See {@see tellsTheUser()}.
 */
enum SemanticSkipReason: string
{
    /** Switched off — the master switch or the search toggle. Not a fault. */
    case FeatureOff = 'feature_off';

    /**
     * Switched ON, and unusable: no host address, or no model named for the
     * job. The commonest half-finished state, and the one that produces a
     * feature which appears to exist and never answers.
     */
    case NotConfigured = 'not_configured';

    /** Too few characters to be worth a round trip. See SemanticQuery::MIN_LENGTH. */
    case QueryTooShort = 'query_too_short';

    /**
     * Nothing to embed at all: the query is operators and nothing else.
     *
     * Not the same as a short query, and the difference is a sentence somebody
     * reads. `is:unread` has no words in it by design, and telling the person
     * who typed it to type a little more so their meaning can be searched is
     * advice about a thing they were not doing.
     */
    case NoFreeText = 'no_free_text';

    /** The host answered, and does not hold the model that was named. */
    case ModelMissing = 'model_missing';

    /** Nothing answered at the address. */
    case HostUnreachable = 'host_unreachable';

    /** The host is there and the model did not answer in time. */
    case TimedOut = 'timed_out';

    /**
     * The model answered, and the DATABASE gave up on the search that used it.
     *
     * NOT THE SAME FAULT AS TimedOut, AND IT USED TO BE REPORTED AS ONE.
     * MessageThreadRepository::cheapRows() runs the vector arm under a
     * statement timeout and re-runs without it on expiry; that give-up path
     * answered TimedOut, so a mailbox whose vector arm was too slow told
     * everybody searching that "the model took too long". The model had already
     * answered — in milliseconds, recorded as a success in ai_call_metric — and
     * the operator sent to look at the model host finds nothing wrong with it,
     * because there is nothing wrong with it.
     *
     * The two failures live in different machines and are fixed by different
     * people. They get different sentences.
     */
    case SearchTooSlow = 'search_too_slow';

    /**
     * The vector arm did not time out; it errored.
     *
     * The catch that produced SearchTooSlow above is on DriverException, which
     * is every driver fault Postgres can raise and not merely a cancelled
     * statement: a missing plmail_embed_distance(), `different vector
     * dimensions` from a row whose stored width disagrees with its column, a
     * connection dropped mid-statement. All of those arrived as "the model took
     * too long" — instantly, on every search, with nothing logged anywhere —
     * which is the most misleading sentence available for a deterministic
     * failure that has nothing to do with the model.
     *
     * The specifics belong in the log and are put there. This is the sentence
     * that tells somebody the log is worth reading.
     */
    case SearchFailed = 'search_failed';

    /** Something came back and it was not a vector this can use. */
    case ModelAnsweredBadly = 'bad_answer';

    /**
     * Whether the person searching is told about this.
     *
     * Off is silence, deliberately. plMail is a complete mail client with no
     * model configured — AiSettings says so at length — and a line under every
     * search explaining which optional feature is switched off would be exactly
     * the "configure AI" nagging that entity refuses. Every other case here
     * happens on an installation where somebody has already said yes, and there
     * the missing half of the search is worth a sentence.
     */
    public function tellsTheUser(): bool
    {
        // Defaulted TOWARDS speaking, which is the safe direction: a reason
        // added later and forgotten here reaches the person searching, and the
        // failure that costs is a sentence too many. Silence is the one that
        // has to be chosen deliberately, and both silent cases are here.
        return match ($this) {
            self::FeatureOff,
            self::NoFreeText => false,
            default          => true,
        };
    }

    /** The line the search page prints. */
    public function messageKey(): string
    {
        return 'search.semantic.skipped.' . $this->value;
    }
}
