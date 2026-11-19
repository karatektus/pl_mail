<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Repository\Label\Label\LabelRepository;

/**
 * What Restore takes when it is pressed in the bin or in Spam: the part that
 * is in there.
 *
 * One reply thrown away, or one message the filter put in Spam, lists its
 * whole conversation in the bin or in Spam — drawn by its thrown-away side
 * alone (BinView). Restore on that row used to hand every message of the
 * conversation to ThreadStatusUpdater::restore(), which puts Inbox on each and
 * takes Archive off: the archived mail of the conversation came back into the
 * inbox, and the user's own sent copies were moved into the inbox folder on
 * the mail server. Delete forever (MessagePurger's callers) and Move to
 * (MoveToService::moveWithinAccount()) already took only the thrown-away
 * part; Restore now does too.
 *
 * The lists are the bin and Spam (scope `trash`, `spam`) and an account's own
 * Trash or Junk folder, a label view whose label carries the role. Named by
 * the browser, like every list (see AllMailView): it can only make a restore
 * take less than the request named, so it needs no authorisation of its own.
 *
 * Restore pressed anywhere else — on an archived conversation in the Archive —
 * still brings the whole conversation back, as it always has.
 */
final readonly class DiscardedView
{
    private const array ROLES = [LabelRole::Trash, LabelRole::Spam];

    private const string LABEL_SCOPE = 'label';

    public function __construct(
        private LabelRepository $labels,
    ) {
    }

    /**
     * Whether a list scope and value name the bin or Spam.
     */
    public function names(string $scope, string $value): bool
    {
        if (LabelRole::Trash->value === $scope || LabelRole::Spam->value === $scope) {
            return true;
        }

        if (self::LABEL_SCOPE !== $scope || false === ctype_digit($value)) {
            return false;
        }

        return true === in_array($this->labels->find((int) $value)?->role, self::ROLES, true);
    }

    /**
     * The messages in the bin or in Spam among a conversation's.
     *
     * @param iterable<Message> $messages
     *
     * @return list<Message>
     */
    public static function discardedAmong(iterable $messages): array
    {
        $discarded = [];

        foreach ($messages as $message) {
            foreach ($message->labels as $label) {
                if (true === in_array($label->role, self::ROLES, true)) {
                    $discarded[] = $message;

                    break;
                }
            }
        }

        return $discarded;
    }
}
