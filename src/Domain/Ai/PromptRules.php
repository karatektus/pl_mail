<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * The instructions every feature that puts mail in front of a model has to say,
 * written once.
 *
 * EXTRACTED FROM WritingTask WHEN THE SECOND READER ARRIVED
 * ─────────────────────────────────────────────────────────
 * {@see \App\Domain\Enum\Ai\WritingTask} held LANGUAGE as a `private const` and
 * was the only thing that needed it, which was correct right up until
 * ThreadSummariser needed the same sentence. Private meant the summariser could
 * not read it, and the only two options were to make the enum's internals
 * public or to copy four lines of prompt into a second file.
 *
 * Copying is the drift this repository has already collapsed twice, and both
 * times after it had happened rather than before: the CSRF check on the server
 * side "had already drifted into six spellings" before ChecksCsrf, and
 * assets/csrf.js was extracted when "nine controllers had grown the same line".
 * A prompt fragment drifts worse than either, because a divergence between two
 * copies of this sentence does not fail — it produces an English summary of a
 * German thread on one code path and not the other, which nothing reports and
 * nobody can tell from the model simply having a bad day.
 *
 * A class of constants rather than a trait or an interface: nothing here has
 * behaviour, nothing needs a per-feature answer, and both readers want the
 * literal text. `App\Domain\Ai` because this is domain vocabulary that
 * services, enums and handlers all read, and it is not an enum, a DTO or a
 * filter.
 */
final class PromptRules
{
    /**
     * Said to every feature, because every feature gets it wrong the same way.
     *
     * These instructions are written in English, and a model reads them as
     * evidence of the language it is supposed to answer in — so a German mail
     * came back with an English reply, and proofreading a German draft quietly
     * translated it. Neither is a thing anybody asked for, and the second one
     * destroys the text it was asked to correct. A summary has the same failure
     * with a sharper edge: the summary is read INSTEAD of the mail, so a German
     * thread summarised into English is a translation nobody asked for and
     * nobody can check without going back to the messages.
     *
     * The last clause is the one doing the work. "Write in the language of the
     * message" alone is not enough when everything around it is English; the
     * instruction has to name that pull and refuse it explicitly.
     *
     * IT BEGINS WITH A SPACE, ON PURPOSE. Both readers append it to a prompt
     * that ends in a full stop, and the leading space is what keeps this one
     * string usable by both without either of them owning the join.
     */
    public const string LANGUAGE = ' Always write in the language of the message you are given:'
        . ' a German message gets a German answer, an English one an English answer, and so on for'
        . ' any other language. Never translate the message into another language, and never switch'
        . ' to English merely because these instructions are written in English.';
}
