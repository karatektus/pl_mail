<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * The colours a Label may carry.
 *
 * Tailwind colour tokens rather than hex, stored verbatim on Label::$color and
 * rendered as chip backgrounds. A token means the same chip in every theme the
 * UI grows, which a hex value cannot: #3b82f6 is a fixed light-mode blue and
 * stays that exact blue on a dark background, where the token resolves to
 * whatever "blue" means there.
 *
 * This exists as an enum rather than as a list on the form because it now has
 * two consumers. The web form offers these choices; JMAP accepts and returns
 * them over Mailbox. Two copies of the vocabulary is how a colour picked on the
 * phone becomes a colour the web cannot render — and the failure is silent on
 * both sides, because a chip with an unknown token simply draws unstyled.
 */
enum LabelColor: string
{
    case Gray = 'gray';
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Violet = 'violet';
    case Pink = 'pink';

    /**
     * The choices as the form wants them: translation key => stored value.
     *
     * @return array<string,string>
     */
    public static function choices(): array
    {
        $choices = [];

        foreach (self::cases() as $case) {
            $choices['label.color.'.$case->value] = $case->value;
        }

        return $choices;
    }
}
