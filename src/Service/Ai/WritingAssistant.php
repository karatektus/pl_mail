<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiFeature;

/**
 * Turns a request from the composer into a prompt, and the answer into text.
 *
 * The prompts live here rather than in the controller because they are the part
 * that will actually be tuned: what makes a rewrite useful rather than
 * florid is a sentence in a system message, and hunting for it inside an HTTP
 * action is how it ends up being changed in one of three places.
 *
 * EVERYTHING IS PLAIN TEXT, IN AND OUT
 * ────────────────────────────────────
 * The composer's editor is HTML, but nothing here produces any. A model asked
 * for HTML produces markup the compose sanitiser then has opinions about, and
 * anything that survives lands in a body whose typed-length calculation
 * subtracts styled containers — so a generated block in a wrapper can make a
 * draft stop autosaving. Plain text inserted as a text node has none of those
 * problems and is what a person would have typed anyway.
 */
final readonly class WritingAssistant
{
    /**
     * How much of the message being answered is worth sending.
     *
     * A reply is shaped by what it answers, but only by the top of it — below
     * the first screen an email is quotes, signatures and legal boilerplate,
     * all of which push the actual question out of the model's attention.
     */
    private const int CONTEXT_BUDGET = 3000;

    /** The same, for what the person has written so far. */
    private const int DRAFT_BUDGET = 3000;

    public function __construct(private AiAssistant $ai)
    {
    }

    public function isAvailable(): bool
    {
        return $this->ai->isEnabledFor(AiFeature::WritingHelp);
    }

    /**
     * @param string|null $draft   what is in the composer now
     * @param string|null $context the message being replied to, if any
     */
    public function write(WritingTask $task, ?string $draft, ?string $context, ?string $subject = null): ?string
    {
        if (false === $this->isAvailable()) {
            return null;
        }

        $draft   = trim(mb_substr((string) $draft, 0, self::DRAFT_BUDGET));
        $context = trim(mb_substr((string) $context, 0, self::CONTEXT_BUDGET));

        // Refused rather than answered. Every task here needs something to work
        // from, and a model handed nothing invents a message on the user's
        // behalf — which is the single worst thing this feature could do.
        if (false === $task->hasEnoughToWorkFrom($draft, $context)) {
            return null;
        }

        $answer = $this->ai->chat(
            AiFeature::WritingHelp,
            [
                ['role' => 'system', 'content' => $task->systemPrompt()],
                ['role' => 'user', 'content' => $this->brief($task, $draft, $context, $subject)],
            ],
            $task->temperature(),
        );

        return null === $answer ? null : $this->tidy($answer);
    }

    private function brief(WritingTask $task, string $draft, string $context, ?string $subject): string
    {
        $parts = [];

        if (null !== $subject && '' !== trim($subject)) {
            $parts[] = 'Subject: ' . trim($subject);
        }

        if ('' !== $context) {
            $parts[] = "The message being replied to:\n" . $context;
        }

        if ('' !== $draft) {
            $parts[] = $task->draftLabel() . "\n" . $draft;
        }

        $parts[] = $task->instruction();

        return implode("\n\n", $parts);
    }

    /**
     * Strip what a model adds when it cannot help itself.
     *
     * Chat-tuned models preface answers ("Sure! Here's a draft:") and wrap them
     * in code fences, and both would be pasted into somebody's email. This
     * removes the two that are unambiguous — a leading fence and a trailing one
     * — and leaves everything else alone: guessing at prose would eventually
     * delete a line somebody wanted.
     */
    private function tidy(string $answer): string
    {
        $text = trim($answer);

        if (1 === preg_match('/^```[a-z]*\n(.*)\n```$/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        return $text;
    }
}
