<?php

declare(strict_types=1);

namespace App\Domain\Enum\Account;

/**
 * What a POP3 sync does with a message once it is safely stored here.
 *
 * Three answers, and the default is the one that cannot lose mail. A POP3
 * server has one mailbox and no concept of a folder: there is nowhere for a
 * message to go, so "keep it" leaves it exactly where it is and a later sync
 * sees it again, recognises it by its UIDL, and skips it. Nothing is read
 * twice, nothing is lost, and the server's copy is the backup.
 *
 * That is not what most POP3 clients do. Thunderbird and Outlook delete after
 * fetching by default, which keeps the server mailbox small and is the reason
 * POP3 has a reputation for mail that exists in exactly one place. This
 * default is the other way round on purpose: plMail already holds the
 * message, so a deletion buys the operator very little and costs them the only
 * second copy.
 *
 * Deleting is per-account and opt-in. See Pop3AccountSyncer for where it is
 * honoured, and note that it is only ever asked for after the message's
 * transaction has committed — a sync that fails to store a message must never
 * have told the server to forget it.
 */
enum Pop3DeleteMode: string
{
    /** Leave every message on the server; a re-fetch recognises it by UIDL. */
    case Keep = 'keep';

    /**
     * Delete after a successful store — RETR's implicit delete, done
     * explicitly so it is not left to a client's interpretation.
     */
    case Delete = 'delete';

    /**
     * Delete once the message has been seen here AND its transaction
     * committed, which is what "Delete" does. Named separately because the
     * setting is read by a person, and "delete after fetch" is the sentence
     * that describes it; the enum keeps both spellings apart so a future
     * "delete when read in the UI" is not mistaken for this one.
     */
    public function deletes(): bool
    {
        return self::Delete === $this;
    }

    /**
     * Anything unrecognised reads as Keep.
     *
     * The value lives in a jsonb settings bag, which a restored backup or a
     * hand edit can put anything into. Defaulting to the destructive answer on
     * a value nobody recognises is the one failure this setting must not have.
     */
    public static function fromSetting(mixed $value): self
    {
        // `tryFrom`, not a comparison against the case: the value in the bag
        // is the enum's backing STRING ('delete'), and `self::Delete ===
        // 'delete'` is false — a comparison between a case and a string that
        // looks like it should work and silently means Keep for every account
        // that asked for Delete. tryFrom() answers null for anything it does
        // not know, which is the same answer as any other junk.
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Keep;
    }
}
