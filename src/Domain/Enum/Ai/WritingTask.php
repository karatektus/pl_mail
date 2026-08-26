<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

/**
 * The things the composer may ask a model to do.
 *
 * A closed set, and closed on purpose: the value arrives in a request body, and
 * a free-form "instruction" parameter would be a prompt an attacker could write
 * — with the user's own mail as context and the answer pasted into the user's
 * own draft. Four verbs cover what people actually want and none of them can be
 * made to mean something else.
 *
 * Each carries its own prompt and its own temperature, because the right
 * settings for "shorten this" and "draft a reply" are not the same: one must
 * not invent and the other must produce something.
 */
enum WritingTask: string
{
    /** Write a first draft of a reply to the message being answered. */
    case Reply = 'reply';

    /** Say the same thing in fewer words. */
    case Shorten = 'shorten';

    /** Say the same thing more formally. */
    case Formal = 'formal';

    /** Fix the spelling and grammar and change nothing else. */
    case Proofread = 'proofread';

    public function systemPrompt(): string
    {
        return match ($this) {
            self::Reply => 'You draft replies to email. Write only the body of the reply, as plain '
                . 'text. No subject line, no greeting the writer has not asked for, no signature, '
                . 'and no preamble about what you are doing. Match the tone of the message being '
                . 'answered. If the message asks questions, answer them.',
            self::Shorten => 'You shorten email. Return the same message with the same meaning and '
                . 'the same tone in fewer words, as plain text. Do not add anything that was not '
                . 'there. Do not explain what you changed.',
            self::Formal => 'You adjust the register of email. Return the same message, saying the '
                . 'same things, in a more formal tone, as plain text. Do not add content. Do not '
                . 'explain what you changed.',
            self::Proofread => 'You correct email. Return the message with spelling, grammar and '
                . 'punctuation fixed and NOTHING else changed — same words, same tone, same '
                . 'structure wherever they are already correct. Do not explain what you changed.',
        };
    }

    public function instruction(): string
    {
        return match ($this) {
            self::Reply     => 'Draft the body of a reply.',
            self::Shorten   => 'Rewrite it shorter.',
            self::Formal    => 'Rewrite it more formally.',
            self::Proofread => 'Return it corrected.',
        };
    }

    public function draftLabel(): string
    {
        return match ($this) {
            self::Reply => 'Notes the writer has jotted down so far:',
            default     => 'The message to work on:',
        };
    }

    /**
     * Higher for the one task that has to produce something new; near zero for
     * the three that must not invent. A proofreader given room to be creative
     * rewrites sentences that were already correct.
     */
    public function temperature(): float
    {
        return match ($this) {
            self::Reply     => 0.4,
            self::Shorten,
            self::Formal    => 0.2,
            self::Proofread => 0.0,
        };
    }

    /**
     * Whether there is anything to work from.
     *
     * A reply can be drafted from the message being answered alone — that is
     * the common case, an empty composer under a mail. The other three operate
     * ON the draft, so without one there is nothing to shorten, and a model
     * handed nothing would invent a message on the user's behalf.
     */
    public function hasEnoughToWorkFrom(string $draft, string $context): bool
    {
        return match ($this) {
            self::Reply => '' !== $draft || '' !== $context,
            default     => '' !== $draft,
        };
    }
}
