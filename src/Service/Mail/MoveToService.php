<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\LabelMove;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Move to": the target label on, the label of the list you were looking at
 * off, as one action.
 *
 * Gmail's meaning of the phrase, and the third way of filing a conversation
 * this interface has. The other two are each half of it: the label menu adds a
 * label and leaves the mail where it was, and a drop on a folder
 * (ThreadStatusUpdater::move()) puts it in that folder and takes it out of
 * every other place. This one is defined by where the person is standing —
 * from the Inbox it is "label and archive", from a label it swaps that label
 * for another — and every other label the conversation wears is left alone.
 *
 * ## The view arrives named, and the server decides what it means
 *
 * The client sends the same scope and value the bulk toolbar already sends
 * (see ListViewResolver for why a view is named rather than posted as a URL)
 * and plan() turns that into the one label that comes off. Anything it does
 * not recognise is a list with no label of its own, where the only thing a
 * move takes away is the Inbox.
 *
 * ## Built from the operations that already exist
 *
 * Nothing here writes a label or talks to a provider itself. Each arm is one
 * or two calls into ThreadStatusUpdater, because every one of those methods
 * carries provider behaviour that took a bug report to get right — archive
 * attaches Archive as well as detaching Inbox, a detach on plain IMAP is a
 * physical move when the label was the folder the mail sat in — and a fourth
 * implementation of "remove Inbox" would start without any of it.
 *
 * What that costs is that a move is several flushes rather than one, so the
 * whole of it runs inside a transaction: a conversation that gained the target
 * and then failed to leave the Inbox is the half-move this action exists not
 * to be.
 */
final readonly class MoveToService
{
    /**
     * The system places the picker offers beside the user's own labels.
     *
     * Narrower than LabelRole::acceptsMoves(), which also admits Archive: that
     * is the rule for a drop on the sidebar, where Archive is a row. Here
     * archiving already has a button of its own two icons to the left.
     *
     * @var list<LabelRole>
     */
    private const array SYSTEM_TARGETS = [LabelRole::Inbox, LabelRole::Spam, LabelRole::Trash];

    /**
     * The two places mail is thrown away to. Leaving either means leaving
     * both — see moveWithinAccount().
     *
     * @var list<LabelRole>
     */
    private const array DISCARDED = [LabelRole::Trash, LabelRole::Spam];

    public function __construct(
        private EntityManagerInterface $em,
        private LabelRepository        $labels,
        private ThreadStatusUpdater    $status,
        private GraphLabelPolicy       $graphLabels,
        private LabelResolver          $labelResolver,
    ) {
    }

    /**
     * The user's label for one of the system places the picker offers, by the
     * role's name — made if it does not exist yet.
     *
     * System labels are created lazily: a mailbox nobody has deleted from has
     * no Trash label, so the picker has no id to put on that row, and a picker
     * that offered Trash only to people who had already used it would be
     * missing the entry on exactly the first occasion it is wanted. The row
     * names the role instead and this turns it into the label.
     *
     * Null for anything outside the three, including roles that exist: asking
     * by name is not a way round accepts().
     */
    public function systemTarget(User $user, string $role): ?Label
    {
        $role = LabelRole::tryFrom($role);

        if (null === $role || false === in_array($role, self::SYSTEM_TARGETS, true)) {
            return null;
        }

        return $this->labelResolver->userSystemLabel($role, $user);
    }

    /**
     * Whether a label is somewhere "Move to" can file mail.
     *
     * Read by the controller before anything is loaded, so a request naming
     * Sent or Drafts is refused whether or not it has a selection to act on.
     */
    public function accepts(Label $target): bool
    {
        return null === $target->role || true === in_array($target->role, self::SYSTEM_TARGETS, true);
    }

    /**
     * Work out what a move from this view to this target takes off.
     *
     * @param string $scope the list's own name for itself — `inbox`, `label`,
     *                      or anything else, which is treated as a list with
     *                      no label of its own
     * @param string $value the label id for `label`; ignored otherwise
     */
    public function plan(User $user, Label $target, string $scope, string $value): LabelMove
    {
        $inbox = $this->labels->findOneByRoleForUser(LabelRole::Inbox, $user);
        $view  = match ($scope) {
            'inbox' => $inbox,
            // The bin and spam are lists with a label of their own, and
            // moving out of them leaves it behind — what Gmail does, and what
            // the word says: mail filed under Receipts from the bin that was
            // still in the bin would not have moved. A user who has neither
            // label yet has nothing in either list.
            'trash' => $this->labels->findOneByRoleForUser(LabelRole::Trash, $user),
            'spam'  => $this->labels->findOneByRoleForUser(LabelRole::Spam, $user),
            'label' => $this->viewedLabel($user, (int) $value),
            default => null,
        };

        if (null !== $view && $view === $target) {
            return new LabelMove($target, null, true);
        }

        if (null !== $view) {
            return new LabelMove($target, $view);
        }

        // A list with no label of its own — search, Starred, Sent, an account's
        // folders. The one thing a move takes away there is the Inbox, and only
        // from mail that is in it. Unless the Inbox is where it is going.
        return new LabelMove($target, LabelRole::Inbox === $target->role ? null : $inbox);
    }

    /**
     * Carry a planned move out on a set of conversations.
     *
     * @param list<MessageThread> $threads
     */
    public function move(array $threads, LabelMove $move): void
    {
        if (true === $move->isNoop) {
            return;
        }

        // Grouped by account for the reason BulkStatusController gives:
        // ThreadStatusUpdater resolves folders from the first message's
        // account, so a selection spanning two mailboxes has to arrive as two
        // calls.
        $byAccount = [];

        foreach ($threads as $thread) {
            foreach ($thread->messages as $message) {
                $byAccount[(int) $message->account->id][] = $message;
            }
        }

        $this->em->wrapInTransaction(function () use ($byAccount, $move): void {
            foreach ($byAccount as $messages) {
                $this->moveWithinAccount($messages, $move);
            }
        });
    }

    /**
     * Whether a conversation still belongs in the list it was moved from.
     *
     * Asked after the move, of the labels the thread now carries, so the
     * answer cannot disagree with what was actually written. A list that is a
     * label — the Inbox, a custom label, the Archive — keeps the row only
     * while the thread still wears it. A list that is not, keeps it: a search
     * result filed under Receipts is still a search result. The bin and spam
     * are the exception, because every list but their own leaves them out.
     *
     * The list frame is re-read after a bulk action either way, so this only
     * decides what the click looks like in the moment before that lands.
     */
    public function staysInView(MessageThread $thread, User $user, string $scope, string $value): bool
    {
        if ('all_mail' === $scope && ((string) $thread->account?->id !== $value
            || $thread->account?->usr?->id !== $user->id)) {
            return false;
        }

        $role = LabelRole::tryFrom($scope);
        $view = match (true) {
            'label' === $scope => $this->labels->find((int) $value),
            // Unread is a role and not a label anything wears — the list is
            // read off the unread counts — so asking whether the thread still
            // carries it would empty the list on every move.
            null !== $role && LabelRole::Unread !== $role => $this->labels->findOneByRoleForUser($role, $user),
            default => null,
        };

        if (null !== $view) {
            return $thread->labels->contains($view);
        }

        foreach ($thread->labels as $label) {
            if (LabelRole::Trash === $label->role || LabelRole::Spam === $label->role) {
                return false;
            }
        }

        return true;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param non-empty-list<Message> $messages all from one account
     */
    private function moveWithinAccount(array $messages, LabelMove $move): void
    {
        $target  = $move->target;
        $leaving = $move->leaving;

        // Only the messages that carry it. A thread is its messages, and they
        // are not all in the same place: the reply you sent is in Sent and was
        // never in the Inbox, so "take it out of the Inbox" has nothing to say
        // about it — and archiving it anyway would file a sent copy under
        // Archive, which is what the archive button has always done to a whole
        // thread and is not what a move from the Inbox means.
        $carriers = null === $leaving ? [] : array_values(array_filter(
            $messages,
            static fn (Message $message): bool => $message->hasLabel($leaving),
        ));

        $fromTheBin = true === in_array($leaving?->role, self::DISCARDED, true);

        // Out of the bin or out of spam, BOTH come off: a spam mail somebody
        // then deleted carries the two, and one that left Trash still marked
        // as spam would be filed away again by the next rule run. The reason
        // restore() removes both, applied to who counts as leaving.
        if (true === $fromTheBin) {
            $carriers = array_values(array_filter($messages, static function (Message $message): bool {
                foreach ($message->labels as $label) {
                    if (true === in_array($label->role, self::DISCARDED, true)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        // The bin and spam are not labels to be worn alongside the others —
        // each is a provider operation with a history. Through move(), which
        // sends Trash to trash() and Spam down the path that resolves to a
        // physical move into the junk folder.
        if (LabelRole::Trash === $target->role || LabelRole::Spam === $target->role) {
            $this->status->move($messages, $target);

            return;
        }

        // Back to the inbox is restore(): Inbox on, and Trash, Spam and Archive
        // off, because a conversation that is in the inbox and still in the
        // archive is in two places at once. The view's own label comes off
        // AFTER it, and the order is the point — restore() re-points a plain
        // IMAP message at the inbox folder, so by the time the label is
        // detached it is no longer the folder the mail sits in and the detach
        // is the database change it should be rather than a second move.
        if (LabelRole::Inbox === $target->role) {
            $this->status->restore($messages);

            if ([] !== $carriers && null === $leaving?->role) {
                $this->status->applyLabel($carriers, $leaving, false);
            }

            return;
        }

        // Attached first, to the whole conversation, and before anything is
        // detached: on plain IMAP the detach decides where the mail physically
        // goes from the labels it still carries, so the destination has to be
        // among them. The ordering ThreadStatusUpdater::moveToFolder() is built
        // around, for the same reason.
        $this->status->applyLabel($messages, $target, true);

        if ([] === $carriers) {
            return;
        }

        // OUT OF THE BIN, UNDER A LABEL — and not into the inbox. There is no
        // single operation for that, so it is the two that exist, in the order
        // that leaves every provider in the right place: restore() is what
        // un-trashes (Gmail drops TRASH and SPAM, IMAP moves the message out
        // of the folder), and archive() then takes it straight back out of
        // the inbox restore() put it in. Detaching Trash as though it were an
        // ordinary label would tell Gmail to remove a label by a local id and
        // tell a plain IMAP server to "archive" a message the row still
        // claimed was in the bin.
        //
        // A real folder on this account is simpler: move() puts the mail in it
        // and takes it out of every other place, the bin included.
        if (true === $fromTheBin) {
            if (true === $this->isFolderOn($target, $this->status->accountOf($messages[0]))) {
                $this->status->move($carriers, $target);

                return;
            }

            $this->status->restore($carriers);
            $this->status->archive($carriers);

            return;
        }

        // Leaving the Inbox for a label that is only a tag IS archiving, and
        // has to be done as one: plMail has no All Mail, so mail that is
        // neither in the Inbox nor filed in a folder lives in Archive, and
        // archive() is the one place that knows what that means per provider.
        // Detaching Inbox by hand would tell a plain IMAP server to archive
        // the message and leave the row still pointing at the inbox folder.
        //
        // When the target is a real folder on this account the detach is the
        // right call instead: the mail goes INTO that folder, and Archive has
        // nothing to do with it.
        if (LabelRole::Inbox === $leaving->role && false === $this->isFolderOn($target, $this->status->accountOf($messages[0]))) {
            $this->status->archive($carriers);

            return;
        }

        $this->status->applyLabel($carriers, $leaving, false);
    }

    /**
     * Whether this label is a place on this account rather than a tag.
     *
     * Per account, because that is where the answer lives: the binding records
     * the IMAP folder or the Exchange folder behind a label, and the same
     * label can be a folder on one mailbox and a tag on the next.
     */
    private function isFolderOn(Label $label, Account $account): bool
    {
        return null !== $label->bindingFor($account)?->mailbox
            || true === $this->graphLabels->pushesAsFolder($label, $account);
    }

    /**
     * The label a `label` view is showing, if it is one a move can leave.
     *
     * Somebody else's label resolves to nothing rather than to an error, as it
     * does in ListViewResolver: the honest reading of "I was looking at a
     * label that is not mine" is a view with no label of its own.
     *
     * The label route takes any label the user owns, system ones included, so
     * the Inbox can be reached as `/mail/label/{id}` and is treated as the
     * Inbox, and the bin and spam likewise. Every other role is refused here —
     * Sent and Drafts say how a message came to exist and are not something a
     * move takes off.
     */
    private function viewedLabel(User $user, int $id): ?Label
    {
        $label = $this->labels->find($id);

        if (null === $label || $label->usr?->id !== $user->id) {
            return null;
        }

        if (null !== $label->role && false === in_array($label->role, self::SYSTEM_TARGETS, true)) {
            return null;
        }

        return $label;
    }
}
