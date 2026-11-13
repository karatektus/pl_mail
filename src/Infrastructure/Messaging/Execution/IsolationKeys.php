<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Execution;

use App\Repository\Mail\Mailbox\MailboxRepository;

/**
 * Which messages must not run at the same time as each other: the ones for
 * the same account (#610).
 *
 * ChildProcessExecutionStrategy runs one child per key at a time, so the
 * key decides both halves of what it promises. Different keys run side by
 * side — that is the isolation. The same key runs in arrival order — that is
 * what keeps the behaviour of the single worker that came before it, where a
 * batch queued by a sync was always handled after the sync, and a mailbox was
 * never asked for by two of plMail's connections at once. IMAP servers limit
 * connections per user, and Graph allows about four concurrent requests per
 * mailbox; neither limit should become plMail's problem because the worker
 * became parallel.
 *
 * Read off the message's public properties by NAME rather than by class,
 * because the ingest queue's messages already agree on the names and a list
 * of classes here would be one more place to forget a new one. A message with
 * none of them gets no key and runs whenever there is room.
 *
 *   accountId      the account itself
 *   mailboxId      an IMAP folder: keyed by its ACCOUNT, looked up, because
 *                  the connection limit that matters is the account's
 *   calendarId     a calendar, on its own
 *   addressBookId  an address book, on its own
 *
 * Calendars and address books are not mapped to their account: they sync over
 * CalDAV/CardDAV or their own Graph and Google endpoints, which have limits of
 * their own, and a slow calendar should not hold up the mail of the account
 * it belongs to.
 */
final readonly class IsolationKeys
{
    public function __construct(
        private MailboxRepository $mailboxes,
    ) {
    }

    public function of(object $message): ?string
    {
        $fields = get_object_vars($message);

        if (true === is_int($fields['accountId'] ?? null)) {
            return 'account:' . $fields['accountId'];
        }

        if (true === is_int($fields['mailboxId'] ?? null)) {
            $accountId = $this->mailboxes->accountIdOf($fields['mailboxId']);

            // A folder deleted since the job was queued: its handler will find
            // nothing to do, and the folder is as good a key as any for that.
            return null !== $accountId ? 'account:' . $accountId : 'mailbox:' . $fields['mailboxId'];
        }

        if (true === is_int($fields['calendarId'] ?? null)) {
            return 'calendar:' . $fields['calendarId'];
        }

        if (true === is_int($fields['addressBookId'] ?? null)) {
            return 'address-book:' . $fields['addressBookId'];
        }

        return null;
    }
}
