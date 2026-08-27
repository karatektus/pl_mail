<?php

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Domain\Enum\Ai\PromptSlot;
use Doctrine\ORM\Mapping as ORM;

/**
 * What an administrator has typed in place of the prompts this release ships.
 *
 * NULL IS THE DEFAULT AND NULL IS A REAL STATE
 * ────────────────────────────────────────────
 * Every field here starts null and null means "use the shipped text". The
 * shipped text is NEVER copied into these columns, not on first save and not on
 * install, and that is the whole design rather than a shortcut: a row that
 * holds a copy of the default is a row that pins this installation to the
 * wording of the release it was saved under. Every later improvement to a
 * prompt — a sentence added because a model kept inventing an outcome — would
 * then reach new installations and silently skip every existing one, with
 * nothing on the page to say so. AiSettings makes the same argument about off
 * being a real state rather than an absence.
 *
 * It also makes "put it back" a deletion rather than a restore. The reset on
 * the admin page writes null; it does not need to know what the default says,
 * so it cannot write a stale copy of it.
 *
 * AN EMBEDDABLE AND NOT A JSON COLUMN
 * ───────────────────────────────────
 * {@see AiPreferences} draws this line already, for two fields that are free
 * text ending up inside a prompt: "a fixed, validated set … where a bound has
 * to be a column length and a clamp rather than a convention readers are
 * trusted to keep." Seven columns is seven things `\d ai_settings` shows an
 * operator, seven things a backup carries by name, and a bound the database
 * itself can hold. A JSON bag would be one column, one migration saved, and no
 * answer at all to "which of these is set" without parsing it.
 *
 * THE CAP IS A BUDGET, NOT A FEAR
 * ───────────────────────────────
 * See {@see MAX_LENGTH}. OllamaClient sends no `num_ctx`, so everything added
 * to a system prompt pushes the actual mail out of the model's window silently
 * rather than erroring — the same reason AiPreferences' own budgets are small.
 */
#[ORM\Embeddable]
final class AiPrompts
{
    /**
     * The ceiling on any one prompt, in characters.
     *
     * 1200 and not AiPreferences::MAX_SYSTEM_PROMPT's 600, and the figure is
     * measured rather than picked: the summary prompt this release ships is 587
     * characters long. A 600 cap would leave an administrator thirteen
     * characters of room on the longest prompt we ship — a cap that the shipped
     * text already fills is a cap that only ever bites the person trying to
     * improve it, which is the one person this feature exists for. 1200 is
     * AiPreferences::MAX_ABOUT_ME's figure, so the application still has two
     * numbers for free text rather than three.
     *
     * What it is protecting is the context window and not the database.
     * ThreadSummariser::TRANSCRIPT_BUDGET is 8000 characters and its docblock
     * works the whole sum out against a 4096-token default: 8000 of transcript,
     * ~230 tokens of system prompt and ~350 of answer come to ~2830, "which
     * fits with room". Two prompts at this cap — a summary rule and a language
     * rule — are ~680 tokens rather than ~230, which spends about half of that
     * room. That is an administrator's choice to make, but it is why the cap is
     * a four-figure number rather than an open textarea.
     *
     * Published to the page as `maxlength` from this constant. Two copies of a
     * limit is how a textarea accepts four thousand characters and reports
     * success while the server stores twelve hundred.
     */
    public const int MAX_LENGTH = 1200;

    #[ORM\Column(name: 'reply', type: 'text', nullable: true)]
    public ?string $reply = null {
        set => self::normalised($value);
    }

    #[ORM\Column(name: 'shorten', type: 'text', nullable: true)]
    public ?string $shorten = null {
        set => self::normalised($value);
    }

    #[ORM\Column(name: 'formal', type: 'text', nullable: true)]
    public ?string $formal = null {
        set => self::normalised($value);
    }

    #[ORM\Column(name: 'proofread', type: 'text', nullable: true)]
    public ?string $proofread = null {
        set => self::normalised($value);
    }

    #[ORM\Column(name: 'summary', type: 'text', nullable: true)]
    public ?string $summary = null {
        set => self::normalised($value);
    }

    #[ORM\Column(name: 'categorise', type: 'text', nullable: true)]
    public ?string $categorise = null {
        set => self::normalised($value);
    }

    #[ORM\Column(name: 'language', type: 'text', nullable: true)]
    public ?string $language = null {
        set => self::normalised($value);
    }

    /**
     * The override for one slot, or null where the shipped text is in force.
     *
     * A match over the seven rather than a variable property name. `$this->{$slot->value}`
     * would work today and would keep working right up until a slot is named
     * something that is not a property, at which point it is a runtime warning
     * inside a prompt assembly instead of a static error here.
     */
    public function of(PromptSlot $slot): ?string
    {
        return match ($slot) {
            PromptSlot::Reply      => $this->reply,
            PromptSlot::Shorten    => $this->shorten,
            PromptSlot::Formal     => $this->formal,
            PromptSlot::Proofread  => $this->proofread,
            PromptSlot::Summary    => $this->summary,
            PromptSlot::Categorise => $this->categorise,
            PromptSlot::Language   => $this->language,
        };
    }

    /** Set one, or clear it back to the shipped text with null or an empty string. */
    public function put(PromptSlot $slot, ?string $text): void
    {
        match ($slot) {
            PromptSlot::Reply      => $this->reply = $text,
            PromptSlot::Shorten    => $this->shorten = $text,
            PromptSlot::Formal     => $this->formal = $text,
            PromptSlot::Proofread  => $this->proofread = $text,
            PromptSlot::Summary    => $this->summary = $text,
            PromptSlot::Categorise => $this->categorise = $text,
            PromptSlot::Language   => $this->language = $text,
        };
    }

    /** Whether anything at all has been overridden. For the chip on the card. */
    public function customisedCount(): int
    {
        $count = 0;

        foreach (PromptSlot::cases() as $slot) {
            if (null !== $this->of($slot)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * One prompt, as it is worth storing: trimmed, capped, and null when empty.
     *
     * EMPTY IS NULL, WHICH IS THE WHOLE FALLBACK. Clearing the box on the admin
     * page is how "put it back" is spelled, so a textarea that comes back with
     * nothing in it must become an absent override and not a stored empty
     * string. A stored '' would be an override that says nothing, and for the
     * language rule that is the exact regression it was added to fix: the model
     * answers in English because everything around it is English, and no test
     * and no page can tell the difference between "the rule is empty" and "the
     * rule is absent" once the column holds one of them.
     *
     * mb_substr BEFORE trim, in that order, for AiPreferences' reason: trimming
     * first would let twelve hundred characters of leading spaces survive the
     * cut and leave nothing of the sentence behind them.
     *
     * Truncated rather than refused, which is the posture every free-text field
     * in this application takes: this arrives from a textarea on a page only an
     * administrator can open, the page publishes the same cap as `maxlength`,
     * and somebody who got past that should lose the tail of the paste rather
     * than the whole of it.
     */
    private static function normalised(?string $value): ?string
    {
        $text = trim(mb_substr((string) $value, 0, self::MAX_LENGTH));

        return '' === $text ? null : $text;
    }
}
