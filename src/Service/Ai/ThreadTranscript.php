<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;

/**
 * A conversation as the one piece of text a summary is made from — and the one
 * piece of text its freshness is measured against.
 *
 * ONE FUNCTION, ONE FACT
 * ──────────────────────
 * This output is used twice and must be byte-identical both times: it is the
 * user message sent to the model, and it is the input to the SHA-256 that
 * decides whether a stored summary still describes this thread. Two builders —
 * one for the prompt, one for the hash — would disagree the first time either
 * was tuned, and the symptom would be a summary that is permanently stale or
 * permanently fresh, with nothing on the page to say which.
 *
 * BUILT FORWARDS, WHICH IS THE OPPOSITE OF ReplyContextReader
 * ───────────────────────────────────────────────────────────
 * That class walks newest-first and reverses, and its docblock says exactly
 * why: a reply is shaped by the message being answered, so a transcript
 * "trimmed from the start would lose the message actually being replied to and
 * keep a greeting from March."
 *
 * For a summary the bias is precisely inverted. The opening of a thread is the
 * request — what this is about, who asked, what for — and everything after it
 * is qualification. Drop the opening and you get a summary that describes the
 * last three replies to a question it never states. So this keeps the head, and
 * then keeps the newest turn as well, because where a conversation has GOT TO
 * is the other half of what a reader wants. What gets dropped is the middle,
 * which is where the "as discussed" and the scheduling back-and-forth live.
 *
 * THE GAP IS ANNOUNCED
 * ────────────────────
 * A model told a conversation was cut says so; a model handed a silently
 * truncated one invents the middle, and an invented middle in a summary is
 * indistinguishable from a real one to the person who asked for a summary
 * precisely so they would not have to read the messages. The marker is English
 * inside a possibly German transcript on purpose: it is a note about the
 * document, not part of it, and PromptRules::LANGUAGE already names and refuses
 * the pull towards English that surrounding English instructions create.
 */
final readonly class ThreadTranscript
{
    /**
     * Where the turns that did not fit used to be.
     *
     * `%d` is how many. A count rather than a vague "some earlier messages",
     * because the difference between two dropped turns and forty is the
     * difference between a summary that is nearly complete and one that is a
     * sample — and a reader who is told forty knows to open the thread.
     */
    private const string ELISION = '[… %d messages omitted here …]';

    public function __construct(private MessageRepository $messages)
    {
    }

    /**
     * The whole conversation, oldest first, trimmed to the budget.
     *
     * In conversation order — received by arrival, sent by send time — and not
     * the association's receivedAt-only order, which drops this account's own
     * replies to the bottom because they have no receivedAt. A summary of a
     * thread whose replies were all at the end would describe an argument in
     * which one side spoke only after the other had finished.
     */
    public function forThread(MessageThread $thread): string
    {
        return $this->forMessages($this->messages->forThreadInConversationOrder($thread));
    }

    /**
     * The same, from a list somebody already has.
     *
     * MailController::thread() runs forThreadInConversationOrder() for the
     * render anyway, so the pane can hand over the hydrated list and pay
     * nothing to find out whether a stored summary is still current. That is
     * the whole reason the freshness check is affordable on every thread open:
     * it is in-memory string work over bodies the page is about to display.
     *
     * @param list<Message> $messages oldest first
     */
    public function forMessages(array $messages): string
    {
        $blocks = array_map(self::block(...), $messages);

        if ([] === $blocks) {
            return '';
        }

        return implode("\n\n", self::trimmed($blocks));
    }

    /**
     * The staleness key.
     *
     * Over the transcript rather than over the thread, which is what makes it
     * total: a new message, a deleted one, a draft edited in place and a row
     * re-pointed into the thread all change this string, while marking read,
     * starring, snoozing and labelling do not appear in it at all. It also
     * means changing TRANSCRIPT_BUDGET or the marker above invalidates every
     * stored summary automatically — correct, because a summary built from
     * different material is a different summary, and free, because it needs no
     * version column of its own.
     *
     * Static because it is a pure function of a string and both readers — the
     * controller that writes a row and the controller that renders one — should
     * be spelling the same call.
     */
    public static function hash(string $transcript): string
    {
        return hash('sha256', $transcript);
    }

    /**
     * One turn.
     *
     * A message with no text part contributes its header line and nothing else,
     * for ReplyContextReader's stated reason: dropping it would silently close
     * a gap in the conversation, and "somebody replied here and we cannot show
     * you what they said" is more use to a model than a turn that never appears.
     */
    private static function block(Message $turn): string
    {
        return trim(implode("\n", [
            'From: ' . trim(((string) $turn->fromName) . ' <' . ((string) $turn->fromAddress) . '>'),
            trim((string) ($turn->bodyText ?? '')),
        ]));
    }

    /**
     * The head, the gap, and the newest turn.
     *
     * The last block is reserved BEFORE the head is filled, which is the whole
     * of the difference between this and a naive walk: fill first and the
     * newest turn is the one thing guaranteed not to fit. The first block is
     * kept whatever it costs, the way ReplyContextReader keeps an over-budget
     * single turn — a transcript with nothing in it is refused outright, and a
     * head that is over budget is still the request the thread is about.
     *
     * +2 on every cost is the blank line the blocks are joined with, counted
     * before the block is kept so the budget describes what is actually sent
     * rather than what was intended. ReplyContextReader counts it the same way.
     *
     * @param list<string> $blocks oldest first, at least one
     *
     * @return list<string>
     */
    private static function trimmed(array $blocks): array
    {
        $total = 0;

        foreach ($blocks as $block) {
            $total += mb_strlen($block) + 2;
        }

        if ($total <= ThreadSummariser::TRANSCRIPT_BUDGET || 1 === count($blocks)) {
            return $blocks;
        }

        $last     = $blocks[array_key_last($blocks)];
        $reserved = mb_strlen($last) + 2 + mb_strlen(sprintf(self::ELISION, count($blocks))) + 2;

        $kept  = [];
        $spent = 0;

        // Every block but the last, which is already spoken for above.
        foreach (array_slice($blocks, 0, -1) as $block) {
            $cost = mb_strlen($block) + 2;

            if ([] !== $kept && $spent + $cost + $reserved > ThreadSummariser::TRANSCRIPT_BUDGET) {
                break;
            }

            $kept[] = $block;
            $spent += $cost;
        }

        $dropped = count($blocks) - count($kept) - 1;

        if (0 === $dropped) {
            // The reservation was pessimistic by exactly the marker: everything
            // fits after all, and announcing a gap that is not there would be a
            // lie about the one thing this marker exists to be honest about.
            return $blocks;
        }

        $kept[] = sprintf(self::ELISION, $dropped);
        $kept[] = $last;

        return $kept;
    }
}
