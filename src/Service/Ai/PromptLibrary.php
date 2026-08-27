<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\Enum\Ai\PromptSlot;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Embeddable\AiPrompts;
use App\Repository\Ai\AiSettingsRepository;

/**
 * What each feature actually says to the model — the administrator's words
 * where there are any, ours where there are not.
 *
 * THE ONE PLACE THAT KNOWS
 * ────────────────────────
 * PromptSlot::shipped() knows what this release ships and AiPrompts holds what
 * somebody typed; neither is the answer on its own, and every caller that
 * resolved the two itself would be a caller that could get it wrong in the
 * direction nothing reports — sending the default on an installation that had
 * replaced it. So there is exactly one resolver, and WritingTask::systemPrompt()
 * was deleted rather than left as a second one.
 *
 * WHY THE JOIN LIVES HERE
 * ───────────────────────
 * PromptRules::LANGUAGE used to begin with a space so that "both readers could
 * append it to a prompt that ends in a full stop without either of them owning
 * the join". That stopped working the moment the sentence could come out of a
 * textarea: nothing types a leading space, and the trim that keeps whitespace
 * from smuggling length past a cap would remove it anyway. So the concatenation
 * is done in one method, with one space, and neither half has to look like
 * anything.
 *
 * THE LANGUAGE RULE IS APPENDED STRUCTURALLY, AND THAT IS NOT AN OPINION
 * ─────────────────────────────────────────────────────────────────────
 * Every prose prompt gets it, from here, whatever it says. An administrator can
 * REWORD the rule — that is the point of exposing it — but there is no way to
 * detach it from a task, and clearing the box restores our wording rather than
 * removing the rule, because an absent override means the shipped text. The
 * reason is the bug it was written for: without it a German mail came back with
 * an English reply and PROOFREADING A GERMAN DRAFT TRANSLATED IT, which
 * destroys the text it was asked to correct and would have to be noticed before
 * the writer pressed send. That failure is silent, so the protection cannot be
 * a checkbox somebody can leave off.
 *
 * Categorisation is the one prompt that does not get it, and deliberately —
 * see {@see \App\Domain\Ai\PromptRules::CATEGORISE}. Its answer is one English
 * token from a closed set, not prose.
 *
 * NO CACHING HERE
 * ───────────────
 * currentOrDefault() is a findOneBy on a one-row table, which is a primary
 * lookup and then the identity map for the rest of the request. A cached copy
 * held in this service would be a second version of the settings that could
 * disagree with the one the admin form just wrote — the thing
 * Version20260828000000 refuses on the grounds that "they would disagree
 * exactly when somebody was watching".
 */
final readonly class PromptLibrary
{
    public function __construct(private AiSettingsRepository $settings)
    {
    }

    /**
     * The whole system message a writing task is sent.
     *
     * @see WritingAssistant::messagesFor() which appends the writer's own
     *      persona AFTER this — the app's instructions come first and whole
     */
    public function forTask(WritingTask $task): string
    {
        return $this->joined($this->text($task->promptSlot()), $this->language());
    }

    /** The whole system message the thread summariser is sent. */
    public function forSummary(): string
    {
        return $this->joined($this->text(PromptSlot::Summary), $this->language());
    }

    /**
     * The whole system message the categoriser is sent — with NO language rule.
     *
     * A method of its own rather than callers reaching for text(Categorise),
     * so the absence is stated in one place and reads as a decision instead of
     * as somebody having forgotten the append that the other two do.
     */
    public function forCategorisation(): string
    {
        return $this->text(PromptSlot::Categorise);
    }

    /** The language rule as it stands: the administrator's wording, or ours. */
    public function language(): string
    {
        return $this->text(PromptSlot::Language);
    }

    /**
     * The text in force for one slot, never empty.
     *
     * The admin page shows this beside the box so somebody can see what they
     * are replacing.
     */
    public function text(PromptSlot $slot): string
    {
        return $this->override($slot) ?? $slot->shipped();
    }

    /**
     * What was typed, or null where the shipped text is in force.
     *
     * The admin page needs the difference: an empty box means "we ship this"
     * and a filled one means "somebody decided otherwise", and a method that
     * folded the two together would make the page unable to say which.
     *
     * RE-NORMALISED ON THE WAY OUT, even though the property hook normalises on
     * the way in. Property hooks are skipped on hydration — see User::$timezone
     * — so a row written by an older build, a configuration restore or a psql
     * session has never been past the setter, and the cap is a budget the
     * message itself has to fit inside rather than a rule about forms.
     */
    public function override(PromptSlot $slot): ?string
    {
        $stored = $this->settings->currentOrDefault()->prompts->of($slot);

        if (null === $stored) {
            return null;
        }

        $text = trim(mb_substr($stored, 0, AiPrompts::MAX_LENGTH));

        return '' === $text ? null : $text;
    }

    /**
     * Two halves of a system message, with exactly one space between them.
     *
     * rtrim on the left because a pasted prompt often ends in a newline, and a
     * prompt that ends "…answer them.\n\n Always write in…" is not wrong so
     * much as it is a different string every time somebody re-saves the same
     * text — which, for the summary, is a different fingerprint and therefore a
     * cache invalidated by whitespace.
     */
    private function joined(string $prompt, string $rule): string
    {
        return rtrim($prompt) . ' ' . trim($rule);
    }
}
