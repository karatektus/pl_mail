<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

/**
 * How much of a conversation the composer's drafting help is given.
 *
 * A SCALE OF GENEROSITY, NOT A SCALE OF CAUTION
 * ─────────────────────────────────────────────
 * plMail talks to a model on the operator's own network, so there is nothing to
 * withhold from and no journey to shorten. What this setting trades is quality
 * against time: a model that has read the whole conversation stops answering a
 * message it has only seen the last turn of, and a model that has read nothing
 * is quick and has nothing to work from.
 *
 * The middle value is what the composer did before this setting existed, which
 * is why it is the default — an upgrade changes nobody's drafts until they
 * choose to change them.
 *
 * WHY THE TOP IS THE THREAD AND NOT THE MAILBOX
 * ─────────────────────────────────────────────
 * A thread has an end. "Everything this person ever wrote to me" has no bound
 * anybody could put on a prompt, and the budget it would spend is the same
 * budget the message actually being answered has to fit in — see
 * WritingAssistant::CONTEXT_BUDGET, which is a character count against a
 * context window that is not on the wire and cannot be negotiated.
 */
enum ReplyContext: string
{
    /**
     * Only what is in the composer.
     *
     * The cheapest and fastest, and the one with a consequence worth saying
     * out loud: "Draft a reply" on an empty composer then has nothing to work
     * from and refuses, because WritingTask::hasEnoughToWorkFrom() will not let
     * a model invent a message on somebody's behalf. The three tasks that work
     * ON a draft — shorten, formal, proofread — are unaffected.
     */
    case None = 'none';

    /** The one message being replied to. What the composer has always done. */
    case Message = 'message';

    /**
     * Every message in the conversation, oldest first, as much of it as the
     * budget holds.
     *
     * Trimmed from the OLD end when it does not fit, because the newest turn is
     * the one being answered — see ComposeAssistController::transcript().
     */
    case Thread = 'thread';

    /** Whether anything at all is read out of the database for a draft. */
    public function readsMail(): bool
    {
        return self::None !== $this;
    }
}
