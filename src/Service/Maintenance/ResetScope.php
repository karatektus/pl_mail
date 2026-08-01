<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

/**
 * How far a partial reset reaches.
 *
 * One object rather than four parameters, because two of the four are not
 * independent and the dependency has to live somewhere: deleting accounts takes
 * the mailbox structure with it, since a label without its account is a foreign
 * key violation waiting to happen. Normalising that in the constructor means no
 * caller can construct the broken combination, and neither the command nor the
 * controller has to remember the rule.
 *
 * The full reset is deliberately NOT a scope. It is a different operation with
 * a different shape — see DataResetter::fullReset().
 */
final readonly class ResetScope
{
    public bool $mailboxes;

    public function __construct(
        bool $mailboxes = false,
        public bool $contacts = false,
        public bool $accounts = false,
        public bool $monitoring = false,
    ) {
        $this->mailboxes = $mailboxes || $accounts;
    }
}
