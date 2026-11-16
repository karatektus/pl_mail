<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Repository\Label\Label\LabelRepository;

/**
 * What Delete takes when it is pressed in a Drafts list: the drafts.
 *
 * A Drafts list shows conversations, and a conversation is listed there because
 * one of its messages is an unsent draft — a reply to mail that was received,
 * often. Trash on such a row, or on a ticked selection of them, used to be the
 * ordinary thread trash: every message in the conversation went to the bin,
 * the mail that was answered along with the half-written answer. The row said
 * "draft to Anna", the shortcut was the one for deleting a draft, and the
 * conversation with Anna was what disappeared.
 *
 * So in a Drafts list a delete is narrowed to the conversation's drafts. They
 * go to the bin like any trash — Undo and Restore work as they do everywhere —
 * and the conversation leaves the list because nothing in it is a live draft
 * any more (MessageThreadRepository::onlyLiveDrafts()). The rest of it stays
 * where it was.
 *
 * Two lists are Drafts lists: the Drafts view itself (scope `drafts`) and an
 * account's own Drafts folder, which is a label view (scope `label`, the label
 * id as value) of a label carrying the Drafts role. Both arrive from the
 * browser, the way every list names itself (see AllMailView). They can only
 * ever make a delete take LESS than the request named — a label id that is
 * somebody else's narrows a delete of the caller's own, already authorised,
 * messages — so they need no authorisation of their own.
 *
 * Everywhere else Delete on a conversation still means the conversation: in
 * the Inbox, a conversation carrying an unsent reply is the mail you were
 * looking at, and that is what its trash button promises.
 */
final readonly class DraftsView
{
    private const string LABEL_SCOPE = 'label';

    public function __construct(
        private LabelRepository $labels,
    ) {
    }

    /**
     * Whether a list scope and value name a Drafts list.
     */
    public function names(string $scope, string $value): bool
    {
        if (LabelRole::Drafts->value === $scope) {
            return true;
        }

        if (self::LABEL_SCOPE !== $scope || false === ctype_digit($value)) {
            return false;
        }

        return LabelRole::Drafts === $this->labels->find((int) $value)?->role;
    }

    /**
     * The drafts among a conversation's messages: the ones wearing Drafts,
     * which is what put the conversation in the list (onlyLiveDrafts() asks
     * the same question).
     *
     * @param iterable<Message> $messages
     *
     * @return list<Message>
     */
    public static function draftsAmong(iterable $messages): array
    {
        $drafts = [];

        foreach ($messages as $message) {
            foreach ($message->labels as $label) {
                if (LabelRole::Drafts === $label->role) {
                    $drafts[] = $message;

                    break;
                }
            }
        }

        return $drafts;
    }
}
