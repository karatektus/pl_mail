<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * The prompt text this application ships, for everything that is not one of
 * WritingTask's four arms.
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
 * AND THEN A SECOND READER ARRIVED FOR ALL OF THEM
 * ────────────────────────────────────────────────
 * SUMMARY was a `private const` on ThreadSummariser and CATEGORISE was a string
 * literal inside ClassifyMailHandler::ask(), for the same good reason LANGUAGE
 * was private: one reader each. Admin → AI is now the second reader of every
 * prompt this application sends — it has to show the shipped text beside the
 * administrator's override and put it back on request — and the argument above
 * repeats itself exactly. A prompt the admin page believes it is showing the
 * default of, while a different default is what actually goes on the wire, is
 * the same silent divergence with an interface built on top of it.
 *
 * So the two that had no enum of their own moved here. WritingTask's four did
 * NOT: they are per-case text that belongs in the same match arm as the
 * temperature and the instruction for that case — see
 * {@see \App\Domain\Enum\Ai\WritingTask::shippedPrompt()} — and
 * {@see \App\Domain\Enum\Ai\PromptSlot} is the one list that names all seven.
 *
 * NONE OF THESE IS NECESSARILY WHAT GETS SENT
 * ───────────────────────────────────────────
 * They are the SHIPPED text. An administrator may replace any of them from
 * Admin → AI, and the only thing that knows which is in force is
 * {@see \App\Service\Ai\PromptLibrary}. Nothing outside that service and the
 * slot enum should read these constants: reading one directly is how a caller
 * ends up sending the default to a model on an installation that has overridden
 * it, which nothing would report.
 *
 * A class of constants rather than a trait or an interface: nothing here has
 * behaviour, nothing needs a per-feature answer, and every reader wants the
 * literal text. `App\Domain\Ai` because this is domain vocabulary that
 * services, enums and handlers all read, and it is not an enum, a DTO or a
 * filter.
 */
final class PromptRules
{
    /**
     * Said to every feature that writes prose, because every one of them gets
     * it wrong the same way.
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
     * IT NO LONGER BEGINS WITH A SPACE. It used to, so that both readers could
     * append it to a prompt ending in a full stop "without either of them
     * owning the join" — and that stopped being possible the moment an
     * administrator could type this text into a textarea, where nothing puts a
     * leading space and trim() would take one off anyway. The join moved to
     * PromptLibrary, which is now the only thing that concatenates a prompt,
     * and it is one space whatever either half looks like.
     */
    public const string LANGUAGE = 'Always write in the language of the message you are given:'
        . ' a German message gets a German answer, an English one an English answer, and so on for'
        . ' any other language. Never translate the message into another language, and never switch'
        . ' to English merely because these instructions are written in English.';

    /**
     * What a summary IS, in the register WritingTask's four arms use.
     *
     * Three of these sentences are load-bearing and the reasons differ.
     *
     * "Plain text, no markdown" because the card renders with textContent and
     * never innerHTML — this is model output derived from somebody's mail — so
     * asterisks and hashes would arrive as literal asterisks and hashes on the
     * page rather than as emphasis.
     *
     * "State only what the messages actually say" because the failure mode of a
     * summary is not being unhelpful, it is being confidently wrong about an
     * outcome — "they agreed to Thursday" for a thread where Thursday was
     * proposed and never answered. The reader does not read the mail
     * underneath; that is the whole point of the feature and the whole of the
     * risk. The explicit instruction to SAY when something was left unanswered
     * gives the model somewhere to put an open question other than into an
     * invented conclusion.
     *
     * "Name people the way the messages name them" because a summary that
     * paraphrases senders into roles ("the supplier", "the client") is not
     * checkable against the thread it sits above.
     *
     * EDITING THIS INVALIDATES EVERY STORED SUMMARY, and so does editing an
     * administrator's replacement for it. See
     * {@see \App\Service\Ai\ThreadSummariser::promptFingerprint()}: what is
     * stored on the row is a hash of the prompt that was actually sent, so the
     * invalidation is a consequence of the text rather than something somebody
     * has to remember to do.
     */
    public const string SUMMARY = 'You summarise email conversations. Write only the summary:'
        . ' no preamble about what you are doing, no heading, no closing remark, and no markdown.'
        . ' Give AT MOST THREE LINES, one per line, and no bullet characters — the card draws those.'
        . ' Each line is one short sentence carrying one thing that happened or one thing that is'
        . ' still open. There is no'
        . ' opening paragraph — the first line is already the summary. Prefer the outcome to the'
        . ' history: where a conversation reached a decision, say what it was and leave out the steps'
        . ' that led to it. Leave out greetings, thanks, apologies and sign-offs entirely; they are'
        . ' never the point of the conversation. State only what the messages actually say — never'
        . ' infer an outcome that is not written down, and say so when something was asked and left'
        . ' unanswered. Name people the way the messages name them.';

    /**
     * One question, one word back — and the parser depends on the second half
     * of that.
     *
     * The five names are {@see \App\Domain\Enum\Mail\MessageCategory}'s values
     * spelled out, because a model cannot be handed an enum. The answer is
     * matched against that set by containment rather than parsed, so a model
     * that adds a full stop or a sentence around the word is still understood —
     * but a prompt that stops asking for ONE of those five words produces
     * answers nothing can interpret, and an uninterpretable answer is a null,
     * which silently leaves every message where the ordinary rules put it. The
     * feature would look switched on and do nothing.
     *
     * NO LANGUAGE RULE IS APPENDED TO THIS ONE, unlike every other prompt here.
     * The answer is not prose for a person to read, it is one English token
     * from a closed set, and telling the model to answer in the language of a
     * German mail is telling it to answer "Werbung" — which is the model
     * getting it right in a spelling the parser has never heard of.
     */
    /**
     * ORDERED, AND THE ORDER IS THE FIX.
     *
     * The previous version defined primary as "mail from a person that expects
     * a reply" and left the model to work out the rest. That definition is
     * precisely what personalised marketing is built to imitate, so the model
     * was being asked to distinguish real correspondence from mail engineered
     * to be indistinguishable from it — using the one test the mail was
     * designed to pass.
     *
     * It failed exactly as you would expect. A job-matching mail from a
     * recruitment platform — a named human sender, the reader's first name in
     * the subject, prose in the second person, and an unsubscribe footer —
     * came back `primary`. Gmail said updates and the header rules said
     * promotions; the model was the only one of the three that was clearly
     * wrong, on the mail whose whole design is to be read as personal.
     *
     * So the bulk question is asked FIRST and answered from things marketing
     * cannot hide — an unsubscribe footer, a no-reply address, a tracking
     * link, a template layout — and primary is what is left when the answer is
     * no. That reverses the burden: a message has to fail to look automated
     * before the personal test is applied at all, rather than passing the
     * personal test on the strength of a greeting.
     *
     * The signs are named individually rather than left as "looks like
     * marketing", because a smaller model given an abstraction reasons about
     * the abstraction and a model given a checklist looks for the items.
     *
     * NO LANGUAGE RULE, deliberately, and PromptLibrary is where that is
     * enforced. Every other prompt gets PromptRules::LANGUAGE appended because
     * its answer is prose somebody reads; this one answers a single English
     * token from a closed set, and telling it to reply in the reader's language
     * would ask for `Werbung` where the parser expects `promotions`.
     *
     * Kept well under AiPrompts::MAX_PROMPT so an administrator has room to
     * improve it — a cap the shipped text already fills is a cap that only ever
     * bites the person this feature exists for.
     */
    public const string CATEGORISE = 'You sort one email into exactly one category.'
        . ' Answer with one word and nothing else, chosen from:'
        . ' primary, social, promotions, updates, forums.'
        . "\n\nFirst: is this bulk mail, sent by a system to many people? Signs are an"
        . ' unsubscribe link, a no-reply or team sender, tracking links, or a template'
        . ' layout. Bulk mail is NEVER primary, however personal it reads — not when it'
        . " uses the reader's name, not when a named person signs it."
        . "\n\nIf it is bulk:"
        . "\nsocial — a social network: follows, likes, mentions, new posts."
        . "\nforums — a mailing list or group, where replies reach many people."
        . "\npromotions — selling, offering or recommending: adverts, deals, newsletters,"
        . ' product news, job matches, suggestions of things to look at.'
        . "\nupdates — a system reporting what already happened on the reader's own"
        . ' account: receipts, bookings, deliveries, password resets, security alerts.'
        . "\nIf both fit: promotions wants you to act, updates records a fact."
        . "\n\nOtherwise: primary — a message one person wrote to this reader and would"
        . ' notice a reply to.';
}
