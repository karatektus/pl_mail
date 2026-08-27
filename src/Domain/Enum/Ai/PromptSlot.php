<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

use App\Domain\Ai\PromptRules;

/**
 * Every system prompt this application sends, as one list an administrator can
 * be shown.
 *
 * WHY A SEVENTH THING EXISTS AT ALL
 * ─────────────────────────────────
 * The prompts were four match arms in WritingTask, one private const in
 * ThreadSummariser, one shared const in PromptRules and one string literal
 * inside a Messenger handler. That is fine while the only reader is the code
 * that sends them and tuning one means a deploy. It stops being fine the moment
 * Admin → AI has to enumerate them: a page that lists prompts needs something
 * to iterate, a stable key to store an override under, and a per-prompt label —
 * and the alternative to this enum is a hand-kept array of seven strings in a
 * template, which is a list that goes stale silently the first time a fifth
 * writing task is added.
 *
 * A BACKED ENUM, BECAUSE THE VALUE IS A COLUMN AND A FORM FIELD
 * ─────────────────────────────────────────────────────────────
 * `reply` is the name of the textarea, the suffix of the column that holds the
 * override, and the key in the translation file. WritingTask's own docblock
 * warns that "the value arrives in a request body" and treats that as a reason
 * to keep the set closed; the same applies here and with the same answer —
 * tryFrom() is the validation, and a name outside the seven is a bug in our own
 * page rather than something to write.
 *
 * THIS ENUM DOES NOT KNOW WHAT IS IN FORCE
 * ────────────────────────────────────────
 * {@see shipped()} is the text this release ships and nothing else. What is
 * actually sent is the administrator's override where there is one, and only
 * {@see \App\Service\Ai\PromptLibrary} can answer that — it is the thing with a
 * database behind it. An enum that could answer "the prompt" would be an enum
 * every caller would ask, and half of them would get the shipped text on an
 * installation that had replaced it.
 */
enum PromptSlot: string
{
    /** Draft a reply to the message being answered. */
    case Reply = 'reply';

    /** Say the same thing in fewer words. */
    case Shorten = 'shorten';

    /** Say the same thing more formally. */
    case Formal = 'formal';

    /** Fix the spelling and grammar and change nothing else. */
    case Proofread = 'proofread';

    /** Turn a whole conversation into a paragraph and a list. */
    case Summary = 'summary';

    /** Decide which tab a message that no rule recognised belongs in. */
    case Categorise = 'categorise';

    /**
     * The rule appended to every prompt above except categorisation.
     *
     * Last in the list because it is read last on the page: the six above are
     * jobs, and this is the sentence all of them carry. See
     * {@see \App\Service\Ai\PromptLibrary::forTask()} for the append, which is
     * structural and is not something an administrator can remove — emptying
     * this box restores the shipped wording rather than deleting the rule.
     */
    case Language = 'language';

    /**
     * The text this release ships for the slot.
     *
     * Pointers rather than copies, deliberately. WritingTask's four stay in
     * WritingTask because they sit in the same match arm as that case's
     * temperature and instruction, and separating a prompt from the temperature
     * it was tuned against is how one gets changed without the other. The three
     * that have no enum of their own live in PromptRules, which is where prompt
     * text with more than one reader has lived since ThreadSummariser needed
     * the language rule.
     */
    public function shipped(): string
    {
        return match ($this) {
            self::Reply      => WritingTask::Reply->shippedPrompt(),
            self::Shorten    => WritingTask::Shorten->shippedPrompt(),
            self::Formal     => WritingTask::Formal->shippedPrompt(),
            self::Proofread  => WritingTask::Proofread->shippedPrompt(),
            self::Summary    => PromptRules::SUMMARY,
            self::Categorise => PromptRules::CATEGORISE,
            self::Language   => PromptRules::LANGUAGE,
        };
    }
}
