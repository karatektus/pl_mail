<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiChatResult;
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
     * Is the writing model already in the host's memory?
     *
     * Asked so the composer can say WHY it is about to be quiet. Not resident
     * means the first token is around thirteen seconds away while 20 GiB comes
     * off disk, and thirteen silent seconds is the whole of the "I press the
     * button and nothing happens" report.
     */
    public function isModelWarm(): bool
    {
        return $this->ai->isModelResident(AiFeature::WritingHelp);
    }

    /**
     * @param string|null $draft   what is in the composer now
     * @param string|null $context the message being replied to, if any
     */
    public function write(WritingTask $task, ?string $draft, ?string $context, ?string $subject = null): ?string
    {
        $messages = $this->messagesFor($task, $draft, $context, $subject);

        if (null === $messages) {
            return null;
        }

        $answer = $this->ai->chat(AiFeature::WritingHelp, $messages, $task->temperature());

        return null === $answer ? null : $this->tidy($answer);
    }

    /**
     * The same answer, arriving as it is written.
     *
     * Yields each token and returns the finished AiChatResult, whose content is
     * already tidied — read it with `$tokens->getReturn()` once the loop ends.
     * Null means the request was refused before anybody was asked anything, on
     * exactly the terms write() refuses on.
     *
     * WHY THE TIDIED TEXT COMES AT THE END RATHER THAN PER TOKEN
     * ──────────────────────────────────────────────────────────
     * tidy() strips a code fence, and a fence is a thing you can only recognise
     * from both ends: the opening ``` is the first token out of the model and
     * the closing one is the last. Tidying as it goes would mean holding the
     * first tokens back to see whether they turn out to be a fence — which
     * spends the one thing streaming buys, the sight of the first word — and
     * would still be guessing.
     *
     * So the tokens are yielded raw, and the return carries the tidied whole.
     * The caller shows the first and commits the second, which also means what
     * is inserted into a draft went through exactly the same cleanup as the
     * unstreamed path rather than through a second, browser-side imitation of
     * it.
     *
     * @param string|null $draft   what is in the composer now
     * @param string|null $context the message being replied to, if any
     *
     * @return \Generator<int, string, void, AiChatResult>|null
     */
    public function stream(WritingTask $task, ?string $draft, ?string $context, ?string $subject = null): ?\Generator
    {
        $messages = $this->messagesFor($task, $draft, $context, $subject);

        if (null === $messages) {
            return null;
        }

        $tokens = $this->ai->chatStream(AiFeature::WritingHelp, $messages, $task->temperature());

        // Not a generator function, for the reason AiAssistant::chatStream()
        // gives: the refusals above have to happen when this is CALLED, not
        // when somebody gets round to iterating it.
        return null === $tokens ? null : $this->tidied($tokens);
    }

    /**
     * @param \Generator<int, string, void, AiChatResult> $tokens
     *
     * @return \Generator<int, string, void, AiChatResult>
     */
    private function tidied(\Generator $tokens): \Generator
    {
        foreach ($tokens as $token) {
            yield $token;
        }

        $result = $tokens->getReturn();

        // A failure carries no content to tidy, and rebuilding it would lose
        // the category the caller needs to say what went wrong.
        if (false === $result->succeeded || null === $result->content) {
            return $result;
        }

        $tidied = $this->tidy($result->content);

        // Everything a model said was a code fence and nothing else. ok()
        // promises content, and an empty draft is not an answer.
        if ('' === $tidied) {
            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $result->timing);
        }

        return AiChatResult::ok($tidied, $result->timing);
    }

    /**
     * The prompt, or null when there is nothing worth sending.
     *
     * Shared by write() and stream() rather than copied into both. The budgets,
     * the refusal and the two-message shape are the part that decides what a
     * model is told about somebody's mail, and two copies of that is one that
     * gets a fix and one that does not.
     *
     * @return list<array{role: string, content: string}>|null
     */
    private function messagesFor(WritingTask $task, ?string $draft, ?string $context, ?string $subject): ?array
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

        return [
            ['role' => 'system', 'content' => $task->systemPrompt()],
            ['role' => 'user', 'content' => $this->brief($task, $draft, $context, $subject)],
        ];
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
