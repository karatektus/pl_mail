<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;

/**
 * What deleting a label involves, worked out before anything is deleted.
 *
 * Deleting a label used to be one statement — remove the row — and on a
 * provider where the label is a FOLDER that was never the whole of it. The
 * folder stayed on the server, the next sync met it as a folder plMail had
 * never seen, and the label came back (issue #40). Deleting the folder there
 * as well means first deciding where the mail inside it goes, because a
 * folder cannot be removed with mail in it without that mail going too.
 *
 * So this answers the questions the dialog and the job both ask:
 *
 *   - which labels go — the label and everything nested under it, children
 *     before parents, since a server will not delete a folder with folders
 *     inside it;
 *   - which of those are folders on a server, per account — an IMAP folder
 *     (the binding has a Mailbox) or an Exchange folder (it has a folder id);
 *   - how much mail is in those folders, and so whether a destination is
 *     needed at all;
 *   - where that mail may go.
 *
 * A label that is only a tag anywhere — a Gmail label, an Outlook category, a
 * label that exists only here — is none of this. Removing a tag loses no
 * mail, so it is deleted as it always was, without a question.
 */
final readonly class LabelDeletion
{
    /**
     * The system places the dropdown offers, in the order it offers them.
     *
     * Archive is here although "Move to" leaves it out: there it has a button
     * of its own, and here it is the answer most people deleting a folder
     * want — the mail kept, out of the way, as it would be in any client.
     *
     * @var list<LabelRole>
     */
    public const array SYSTEM_DESTINATIONS = [LabelRole::Inbox, LabelRole::Archive, LabelRole::Trash];

    public function __construct(
        private LabelRepository   $labels,
        private MessageRepository $messages,
        private LabelResolver     $labelResolver,
    ) {
    }

    /**
     * The label and every label nested under it, deepest first.
     *
     * Deepest first because that is the only order a server will delete
     * nested folders in, and the order the remote phase walks.
     *
     * Asked of the database rather than read off Label::$children. That
     * collection is the inverse side of the parent link, and a label nested in
     * the same unit of work — a sync that just made "Work/Invoices" — is not
     * in it until the parent is loaded again. A delete that missed a nested
     * folder would leave it on the server, which is the bug this exists for.
     *
     * @return list<Label>
     */
    public function subtree(Label $label): array
    {
        $subtree = [];

        foreach ($this->labels->findBy(['parent' => $label]) as $child) {
            array_push($subtree, ...$this->subtree($child));
        }

        $subtree[] = $label;

        return $subtree;
    }

    /**
     * Every binding in the subtree that is a folder on a mail server.
     *
     * @return list<LabelBinding>
     */
    public function folderBindings(Label $label): array
    {
        $bindings = [];

        foreach ($this->subtree($label) as $member) {
            foreach ($member->bindings as $binding) {
                if (true === self::isFolder($binding)) {
                    $bindings[] = $binding;
                }
            }
        }

        return $bindings;
    }

    /**
     * Whether deleting this label means deleting folders on a server, which
     * is what sends it to DeleteLabelHandler rather than straight out.
     */
    public function isOnAServer(Label $label): bool
    {
        return [] !== $this->folderBindings($label);
    }

    /**
     * How many messages sit in this label's folders, its nested ones included.
     *
     * Counted per binding, on the binding's own account: the same label can be
     * a folder on one mailbox and a tag on another, and only the mail in the
     * folder has to move. A message in two nested folders cannot happen on a
     * server — mail is in one folder — so the sum does not count anything twice
     * that matters.
     */
    public function mailInFolders(Label $label): int
    {
        $count = 0;

        foreach ($this->folderBindings($label) as $binding) {
            $count += $this->messages->countWithLabelForAccount(
                (int) $binding->account?->id,
                (int) $binding->label?->id,
            );
        }

        return $count;
    }

    /**
     * The first folder in the subtree whose mail plMail is still importing,
     * by the label's full name, or null when there is none.
     *
     * A folder like that has mail on the server that is not here yet, so the
     * count above says less than the server would: the dialog asks nothing
     * (or too little), nothing is moved for the mail that has not arrived,
     * and the server then reports the folder full for the ten minutes the job
     * waits before it gives up. Asked first, the delete simply waits until
     * the import is done.
     */
    public function stillImporting(Label $label): ?string
    {
        foreach ($this->folderBindings($label) as $binding) {
            if (true === $binding->mailbox?->isImporting()) {
                return $binding->label?->fullName;
            }
        }

        return null;
    }

    /**
     * Where the mail may go, as dropdown value => the label it stands for.
     *
     * The three system places, then the user's own labels that are a folder on
     * at least one account — leaving out this label and everything under it,
     * which are about to stop existing. A folder on another account than the
     * mail's is allowed: there the move files the mail under that label and
     * out of the folder being deleted, which on IMAP is the archive, as any
     * move to a label that is not a folder on that account already is.
     *
     * The system labels are made if they do not exist yet, for the reason
     * MoveToService::systemTarget() gives: they are created lazily, and a
     * dropdown that only offered Trash to someone who had already deleted
     * something would be missing it the first time it is wanted.
     *
     * @return array<string, Label>
     */
    public function destinations(Label $label, User $user): array
    {
        $destinations = [];

        foreach (self::SYSTEM_DESTINATIONS as $role) {
            $destinations[$role->value] = $this->labelResolver->userSystemLabel($role, $user);
        }

        $leaving = array_map(static fn (Label $member): ?int => $member->id, $this->subtree($label));

        foreach ($this->labels->findForUserTreeOrdered($user) as $candidate) {
            if (true === $candidate->isSystem || true === in_array($candidate->id, $leaving, true)) {
                continue;
            }

            foreach ($candidate->bindings as $binding) {
                if (true === self::isFolder($binding)) {
                    $destinations['label:' . $candidate->id] = $candidate;

                    break;
                }
            }
        }

        return $destinations;
    }

    /**
     * The label a dropdown value names, or null when it is not one of the
     * values this label's dialog offers.
     *
     * Resolved against destinations() rather than parsed, so a value the
     * dialog did not offer — somebody else's label, Sent, the label being
     * deleted — is refused by the same rule that left it out.
     */
    public function destination(Label $label, User $user, string $value): ?Label
    {
        return $this->destinations($label, $user)[$value] ?? null;
    }

    /**
     * A folder on a server: an IMAP folder, or an Exchange one.
     *
     * Not a Gmail label and not an Exchange category. Both are tags; the mail
     * carrying them lives somewhere else, and removing them moves nothing.
     */
    public static function isFolder(LabelBinding $binding): bool
    {
        return null !== $binding->mailbox || null !== $binding->graphFolderId;
    }
}
