<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiChatResult;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiFeature;
use App\Entity\Embeddable\AiPreferences;
use App\Entity\User\User;

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
     * How much of the conversation being answered is worth sending.
     *
     * A reply is shaped by what it answers, but only by the top of it — below
     * the first screen an email is quotes, signatures and legal boilerplate,
     * all of which push the actual question out of the model's attention.
     *
     * PUBLIC, because ComposeAssistController assembles a whole-thread
     * transcript against exactly this number when somebody has asked for one
     * (AiPreferences::$replyContext). It has to be the same number: the
     * transcript is trimmed from the OLD end so the newest turn survives, and
     * the blind mb_substr() below trims from the new end — so a transcript
     * built to a larger budget would lose precisely the message being replied
     * to. One constant, read by both.
     */
    public const int CONTEXT_BUDGET = 3000;

    /** The same, for what the person has written so far. */
    private const int DRAFT_BUDGET = 3000;

    /**
     * Both, and not one wrapping the other. AiAssistant is the door to the
     * model and AiPermissions is the gate in front of it; a service that held
     * only the gate could not make a call, and one that held only the door
     * could not ask whose mail this is.
     */
    public function __construct(
        private AiAssistant   $ai,
        private AiPermissions $permissions,
    ) {
    }

    /**
     * Whether THIS person may be offered drafting help.
     *
     * A user rather than nobody, because there are two switches and the
     * installation's is only one of them — see AiPermissions. Passing the user
     * in rather than reaching for a Security service is what lets the streamed
     * path capture it in the action and read it inside the response callback,
     * where a repository call would be a query against a kernel that has
     * finished with the request.
     */
    public function isAvailableFor(?User $user): bool
    {
        return $this->permissions->allows($user, AiFeature::WritingHelp);
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
     * @param User        $user    whose composer this is — the floor under the
     *                             installation's ceiling, and whose persona is
     *                             appended to the app's own instructions
     * @param string|null $draft   what is in the composer now
     * @param string|null $context the conversation being replied to, if any
     */
    public function write(User $user, WritingTask $task, ?string $draft, ?string $context, ?string $subject = null): ?string
    {
        $messages = $this->messagesFor($user, $task, $draft, $context, $subject);

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
     * @param User        $user    whose composer this is; see write()
     * @param string|null $draft   what is in the composer now
     * @param string|null $context the conversation being replied to, if any
     *
     * @return \Generator<int, string, void, AiChatResult>|null
     */
    public function stream(User $user, WritingTask $task, ?string $draft, ?string $context, ?string $subject = null): ?\Generator
    {
        $messages = $this->messagesFor($user, $task, $draft, $context, $subject);

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
     * THE USER IS IN THE SIGNATURE, NOT LOOKED UP
     * ───────────────────────────────────────────
     * This is one of the four places in src/ that build content for a model,
     * and the parameter is the enforcement: it is not possible to assemble a
     * prompt here without having named whose composer it is, so the per-user
     * switch cannot be forgotten by a caller added later.
     *
     * @return list<array{role: string, content: string}>|null
     */
    private function messagesFor(User $user, WritingTask $task, ?string $draft, ?string $context, ?string $subject): ?array
    {
        if (false === $this->isAvailableFor($user)) {
            return null;
        }

        $draft   = trim(mb_substr((string) $draft, 0, self::DRAFT_BUDGET));
        $context = trim(mb_substr((string) $context, 0, self::CONTEXT_BUDGET));

        // UNCHANGED, and the persona is deliberately not part of it. This asks
        // whether there is a MESSAGE to work on; a standing note about who the
        // writer is is not one, and folding it in would make an empty composer
        // under no message start generating invented mail — the exact case this
        // refusal exists to prevent.
        if (false === $task->hasEnoughToWorkFrom($draft, $context)) {
            return null;
        }

        return [
            ['role' => 'system', 'content' => $task->systemPrompt() . $this->persona($user)],
            ['role' => 'user', 'content' => $this->brief($task, $draft, $context, $subject)],
        ];
    }

    /**
     * What the writer has said about themselves, appended to what the app says.
     *
     * APPENDED, NEVER SUBSTITUTED, AND NEVER IN THE USER MESSAGE
     * ──────────────────────────────────────────────────────────
     * The app's own instructions come first and whole — including
     * PromptRules::LANGUAGE, which is the one that stopped a German mail
     * coming back with an English reply and stopped proofreading a German draft
     * silently translating it. Replacing the system content with a user string
     * would drop that, the plain-text instruction and the "no preamble"
     * instruction in one go, and the symptom would be a worse draft rather than
     * an error.
     *
     * The system element, and not brief(). brief() is the MAIL side of the
     * boundary — subject, the conversation being replied to, the draft — and a
     * standing note about the writer is not mail. Keeping the two apart is what
     * makes each of them separately budgetable.
     *
     * WHAT THE CLOSING SENTENCE IS AND IS NOT
     * ───────────────────────────────────────
     * A nudge, not a mechanism. Models have no reliable precedence between
     * parts of one system message, so a person who writes "answer in English"
     * into their note may well get English back for a German mail. That is
     * acceptable here and nowhere else: the only party a writer can talk out of
     * the rules is themselves, on their own draft, which they read before they
     * send it. It is the second reason categorisation gets no persona at all.
     *
     * Re-truncated here even though the setter truncates. Property hooks are
     * skipped on hydration (see User::$timezone), so a row written by an older
     * build, a config restore or psql has never been past the setter — and the
     * cap is a budget the message itself has to fit inside.
     */
    private function persona(User $user): string
    {
        $prefs  = $user->aiPreferences;
        $blocks = [];

        if ('' !== trim($prefs->aboutMe)) {
            $blocks[] = "About the writer, in their own words:\n"
                . trim(mb_substr($prefs->aboutMe, 0, AiPreferences::MAX_ABOUT_ME));
        }

        if ('' !== trim($prefs->systemPrompt)) {
            $blocks[] = "How the writer has asked to be written for:\n"
                . trim(mb_substr($prefs->systemPrompt, 0, AiPreferences::MAX_SYSTEM_PROMPT));
        }

        if ([] === $blocks) {
            return '';
        }

        return "\n\n" . implode("\n\n", $blocks)
            . "\n\nThe two notes above are the writer's preferences. Where they conflict with"
            . ' the instructions at the top of this message, the instructions at the top win.';
    }

    private function brief(WritingTask $task, string $draft, string $context, ?string $subject): string
    {
        $parts = [];

        if (null !== $subject && '' !== trim($subject)) {
            $parts[] = 'Subject: ' . trim($subject);
        }

        if ('' !== $context) {
            // "The conversation" rather than "the message", because it may be
            // either: ComposeAssistController hands over one message or a whole
            // thread depending on AiPreferences::$replyContext, and a label
            // that named one of the two would be wrong half the time.
            $parts[] = "The conversation being replied to:\n" . $context;
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
