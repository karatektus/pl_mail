<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What the reading pane shows once the conversation in it has left the list:
 * archived, deleted, moved, snoozed or reported.
 *
 * Positions in the list, not "older" and "newer", because the list is the
 * thing on screen and the one beside the open row is what the reader expects
 * whichever way the list happens to be sorted.
 *
 * `List` is what plMail always did, and stays the default. See
 * assets/controllers/mail/mail_pane_controller.js#leaving.
 */
enum AutoAdvance: string
{
    case List     = 'list';
    case Next     = 'next';
    case Previous = 'previous';

    /**
     * Whatever was stored, read charitably — the settings bag is untyped and
     * may hold a value written by an older version, or nothing at all.
     */
    public static function fromSetting(mixed $value): self
    {
        return true === is_string($value) ? self::tryFrom($value) ?? self::List : self::List;
    }
}
