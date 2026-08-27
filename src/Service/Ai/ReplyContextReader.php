<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\Enum\Ai\ReplyContext;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;

/**
 * What the composer hands the model about the conversation being answered.
 *
 * Its own class rather than two private methods on ComposeAssistController,
 * for the reason MessageEmbedder is its own class: "what a message is, as far
 * as a model is concerned" is a decision worth being able to test and worth
 * having in exactly one place. The controller keeps what only a controller can
 * do — resolving an id, checking that the person owns it — and this decides how
 * much of the result to say.
 *
 * MORE CONTEXT IS BETTER DRAFTS AND LONGER WAITS
 * ──────────────────────────────────────────────
 * That is the whole trade and there is no other one. plMail talks to a model on
 * the operator's own network, so nothing here is being withheld from anybody;
 * what a longer transcript costs is tokens, on a GPU the composer and the
 * indexer already queue behind each other on.
 */
final readonly class ReplyContextReader
{
    public function __construct(private MessageRepository $messages)
    {
    }

    /**
     * The text for one draft, or null when there is nothing to say.
     *
     * The None arm is here as well as in the caller's early return, and that is
     * not a duplicate: the caller skips the database read entirely, which is
     * the point of it being there, and this makes the answer total so a fourth
     * depth cannot be added and quietly fall through to "the whole thread".
     */
    public function textFor(ReplyContext $depth, Message $message): ?string
    {
        $thread = $message->thread;

        return match (true) {
            ReplyContext::None === $depth              => null,
            ReplyContext::Thread !== $depth            => $message->bodyText,
            false === $thread instanceof MessageThread => $message->bodyText,
            default                                    => $this->transcript($thread),
        };
    }

    /**
     * The whole conversation as one piece of text, newest turn guaranteed.
     *
     * WHY IT IS BUILT BACKWARDS AND THEN REVERSED
     * ───────────────────────────────────────────
     * WritingAssistant trims its context with mb_substr() from the START, which
     * is right for one message and wrong for a transcript: a thread assembled
     * oldest-first and then cut to length would lose the message actually being
     * replied to and keep a greeting from March. So the walk goes newest first,
     * stops when the budget is spent, and the kept part is put back into
     * chronological order — which is the order a model reads a conversation in.
     *
     * The budget is WritingAssistant::CONTEXT_BUDGET exactly, not a fraction of
     * it and not a larger number, so the trim on the other side never fires on
     * anything this produced. The one exception is a single turn longer than the
     * whole budget: it is kept anyway, because a transcript with nothing in it
     * would refuse the draft outright, and the far side then takes the head of
     * it — which is the part that carries the question.
     *
     * A message with no text part contributes its header line and nothing else.
     * Dropping it would silently close a gap in the conversation, and "somebody
     * replied here and we cannot show you what they said" is more use to a model
     * than a turn that never appears at all.
     */
    private function transcript(MessageThread $thread): string
    {
        $kept  = [];
        $spent = 0;

        foreach (array_reverse($this->messages->forThreadInConversationOrder($thread)) as $turn) {
            $block = trim(implode("\n", [
                'From: ' . trim(((string) $turn->fromName) . ' <' . ((string) $turn->fromAddress) . '>'),
                trim((string) ($turn->bodyText ?? '')),
            ]));

            // +2 for the blank line this block is joined with. Counted before
            // the block is kept, so the budget describes what the caller
            // actually receives rather than what was intended.
            $cost = mb_strlen($block) + 2;

            if ([] !== $kept && $spent + $cost > WritingAssistant::CONTEXT_BUDGET) {
                break;
            }

            $kept[] = $block;
            $spent += $cost;
        }

        return implode("\n\n", array_reverse($kept));
    }
}
