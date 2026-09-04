<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What decides which tab a message lands in.
 *
 * TWO ANSWERS, AND THEY ARE NOT INTERCHANGEABLE. The rules read headers — a
 * List-Unsubscribe, a List-Id, a bulk precedence — and answer the same way
 * every time for the same mail, on any installation, with nothing switched on.
 * The assistant reads the message and answers what it thinks the message is
 * for. One is predictable and literal; the other is better at mail that does
 * not announce itself and is occasionally confidently wrong.
 *
 * Which is preferable is genuinely a matter of taste, which is why it is a
 * setting rather than a constant somebody argued about once.
 */
enum CategorySource: string
{
    /**
     * Headers only, and the model is not consulted at all.
     *
     * The default, and it is the conservative one deliberately: plMail is a
     * complete mail client with no model configured, and a fresh installation
     * that quietly asked one to sort the mail would be making a decision
     * nobody took.
     *
     * NOTE FOR ANYONE UPGRADING. Before this setting existed the model's
     * verdict was consulted as a TIE-BREAK — used only where the header
     * cascade found nothing and fell through to Primary. That was invisible:
     * there was no way to see it, prefer it, or switch it off short of
     * disabling categorisation entirely. It is now this enum's business, and
     * choosing Rules means what it says.
     */
    case Rules = 'rules';

    /**
     * The assistant decides, and the rules catch what it has not reached.
     *
     * Not "the assistant only": categorisation is asynchronous, so mail that
     * has just arrived has no verdict yet and mail that arrived before the
     * feature was switched on may never get one. Falling through to the rules
     * for those is the difference between a tab that fills in as the model
     * works and one that is wrong until it does.
     */
    case Assistant = 'assistant';

    /** Whether the stored model verdict is read at all. */
    public function usesAssistant(): bool
    {
        return self::Assistant === $this;
    }

    /** Translation key for the option's label. */
    public function labelKey(): string
    {
        return 'settings.sorting.source.' . $this->value;
    }

    /** Translation key for the line under it. */
    public function helpKey(): string
    {
        return 'settings.sorting.source.' . $this->value . '_help';
    }

    /**
     * Whatever was stored or posted, read charitably.
     *
     * The column is a string and the form is whatever arrives, so an
     * unrecognised value falls back rather than throwing: a hand-edited
     * request should sort mail the ordinary way, not 500.
     */
    public static function from_(mixed $value): self
    {
        return true === is_string($value) ? self::tryFrom($value) ?? self::Rules : self::Rules;
    }
}
