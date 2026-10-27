<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Tell a JMAP account's server about a change made to messages here.
 *
 * Shaped like ApplyGmailLabelsMessage, because a JMAP mailbox is shaped like
 * a Gmail label: the change is a set of labels to add and a set to remove, and
 * the handler turns each into a mailbox id. An entry is a plMail label id, or
 * `role:<role>` for a system label — the Archive mailbox may not exist on the
 * server yet, and a role is what lets the handler make it.
 *
 * `$only` is the role a message is moved to on its own, leaving every other
 * mailbox: Trash, which on a JMAP server means "in the bin", not "in the bin
 * and still in Projects". `$keywords` is keyword => whether it is set.
 * `$destroy` is a discarded draft, gone from the server entirely.
 *
 * Ids, never entities: the payload crosses the queue.
 */
final readonly class ApplyJmapChangesMessage
{
    public const string ROLE_PREFIX = 'role:';

    /**
     * @param list<int>            $messageIds
     * @param list<string>         $add
     * @param list<string>         $remove
     * @param array<string, bool>  $keywords
     */
    public function __construct(
        public int     $accountId,
        public array   $messageIds,
        public array   $add = [],
        public array   $remove = [],
        public array   $keywords = [],
        public ?string $only = null,
        public bool    $destroy = false,
    ) {
    }
}
