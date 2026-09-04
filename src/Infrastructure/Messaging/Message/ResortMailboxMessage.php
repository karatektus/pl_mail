<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Re-file one person's mail after they changed what sorts it.
 *
 * WHY THIS IS AFFORDABLE, WHICH IS THE WHOLE ARGUMENT FOR IT EXISTING.
 * MessageCategorizer reads only PERSISTED data — the stored headers, the
 * provider's labels, the sender, the model's stored verdict — and that is the
 * entire design of the class. Nothing about a re-sort asks another machine
 * anything: no IMAP, no model, no network at all. It is a scan of rows the
 * database already has, a PHP cascade over each, and a column write.
 *
 * So "change the setting, then ask an administrator to run a command" was the
 * wrong answer. It was chosen out of caution about doing a hundred thousand
 * things inside an HTTP request, which is a real concern with a wrong fix
 * attached: the answer to work too big for a request is a worker, and this
 * application has four of them. The command stays — an operator re-sorting
 * every mailbox after a rule change still wants it — but a person changing
 * their own setting no longer has to go and find one.
 *
 * ONLY WHEN SOMETHING ACTUALLY CHANGED. The settings card submits on change, so
 * a dispatch on every save would re-file the mailbox each time somebody pressed
 * the option that was already selected.
 */
final readonly class ResortMailboxMessage
{
    public function __construct(
        /**
         * Whose mail to re-file. The user rather than the account, because the
         * setting is theirs and it applies to every account they have — sorting
         * that disagreed between two accounts in one inbox would be worse than
         * either answer.
         */
        public int $userId,
    ) {
    }
}
