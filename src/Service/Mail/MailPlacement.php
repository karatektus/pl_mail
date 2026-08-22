<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;

/**
 * Where a conversation currently sits, and therefore which actions mean
 * anything on it.
 *
 * Two reports, one cause. Deleting a mail already in the trash took the row off
 * the list while the mail stayed exactly where it was — trashing is "add the
 * Trash label", and adding a label a message already carries is a no-op that
 * the list nevertheless treated as a change. And spam and trash both offered
 * Archive and Snooze, which is the same question from the other side: what
 * would archiving something out of the bin do?
 *
 * Read from the conversation's own labels rather than from which list the user
 * clicked in. A thread reached from a search result, from a live update or by
 * URL has no list behind it, and a check against the current route would go
 * quietly back to offering the wrong controls in exactly those cases. What the
 * mail carries is true everywhere.
 *
 * A service rather than only a Twig function, because the templates are not the
 * only thing that has to agree with it: /status/{type}/{id}/purge refuses
 * anything this does not call discarded, and an interface that hides a control
 * while the route behind it stays open is not a rule, it is a habit.
 */
final class MailPlacement
{
    public const string TRASH   = 'trash';
    public const string SPAM    = 'spam';
    public const string ARCHIVE = 'archive';
    public const string NORMAL  = 'normal';

    /**
     * Trash wins over Spam when something carries both, because deleting is the
     * more final of the two and the controls should describe the more final
     * one.
     *
     * @return self::TRASH|self::SPAM|self::ARCHIVE|self::NORMAL
     */
    public function of(Message|MessageThread|null $entity): string
    {
        if (null === $entity) {
            return self::NORMAL;
        }

        $roles = [];

        foreach ($entity->labels as $label) {
            if (null !== $label->role) {
                $roles[] = $label->role;
            }
        }

        if (in_array(LabelRole::Trash, $roles, true)) {
            return self::TRASH;
        }

        if (in_array(LabelRole::Spam, $roles, true)) {
            return self::SPAM;
        }

        // Archive is not a discarded state — nothing is hidden from an archived
        // conversation and it is not a candidate for deleting forever. It is
        // named only so the row can offer the way back, which the archive had
        // as little of as the bin did: an archived thread could be reached from
        // no action anywhere in the interface, because Archive itself was the
        // only thing on offer and it was already applied.
        if (in_array(LabelRole::Archive, $roles, true)) {
            return self::ARCHIVE;
        }

        return self::NORMAL;
    }

    /**
     * Already thrown away once — the only state from which mail may be deleted
     * for good.
     *
     * Not because a purge could not work elsewhere, but because "delete
     * forever" one click from "archive" has no undo to catch the mistake. The
     * bin IS the confirmation step, and it is one the user has already taken.
     */
    public function isDiscarded(Message|MessageThread|null $entity): bool
    {
        return in_array($this->of($entity), [self::TRASH, self::SPAM], true);
    }

    /**
     * Somewhere the conversation can be brought back INTO the inbox from.
     *
     * Wider than isDiscarded() on purpose: an archived mail is not discarded
     * and still needs a way home. "Archive" was offered on mail already in the
     * archive, where it does nothing, and "move to inbox" was offered nowhere at
     * all — so archiving was as much a one-way trip as deleting.
     */
    public function isRestorable(Message|MessageThread|null $entity): bool
    {
        return self::NORMAL !== $this->of($entity);
    }
}
