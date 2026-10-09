<?php

declare(strict_types=1);

namespace App\Domain\Enum\Template;

/**
 * The variables a template may contain, by the name they are written with.
 *
 * A closed list rather than "whatever is between the braces": a token that is
 * not one of these is left in the text exactly as it was typed, which is what
 * keeps a template that merely talks about `{{ curly braces }}` from losing a
 * sentence to a renderer that thought it knew better.
 */
enum TemplateVariable: string
{
    case RecipientFirstName = 'recipient.first_name';
    case RecipientName      = 'recipient.name';
    case RecipientEmail     = 'recipient.email';
    case SenderName         = 'sender.name';
    case SenderEmail        = 'sender.email';
    case Signature          = 'signature';
    case Date               = 'date';

    /**
     * Is this one filled in by the composer rather than by the server?
     *
     * The recipient is the one thing that is routinely not known when a
     * template is inserted — people pick the template first and the address
     * second — so these three are handed to the browser as markers and filled
     * there, when a recipient exists. See TemplateRenderer.
     */
    public function isRecipient(): bool
    {
        return match ($this) {
            self::RecipientFirstName, self::RecipientName, self::RecipientEmail => true,
            self::SenderName, self::SenderEmail, self::Signature, self::Date     => false,
        };
    }

    /** The heading this variable is listed under in the editor's menu. */
    public function group(): string
    {
        return match ($this) {
            self::RecipientFirstName, self::RecipientName, self::RecipientEmail => 'recipient',
            self::SenderName, self::SenderEmail, self::Signature                => 'sender',
            self::Date                                                          => 'date',
        };
    }

    /** Translation key of the name a person reads on the chip. */
    public function labelKey(): string
    {
        return 'templates.variable.' . str_replace('.', '_', $this->value);
    }
}
