<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\SidebarCounts;
use App\Service\Mail\UserAccounts;

/**
 * Which labels the "Move to" picker offers and which the "Label as" picker
 * offers (#221 step 3).
 *
 * A folder is a place and a label is a tag, and which one a label is depends
 * on the account: "Work" can be a folder on a plain IMAP account and a Gmail
 * label beside it. So each picker is asked per account:
 *
 *  - plain IMAP: a place where the label has a folder there, a tag where it
 *    has none (a keyword, or plMail's alone);
 *  - Microsoft: a place where it has an Outlook folder, a category otherwise;
 *  - Gmail: every label is a tag. "Move to" offers the system places only,
 *    unless the account says otherwise (Account::$gmailMoveOffersLabels),
 *    and then a label there is moved to the way Gmail does it — labelled,
 *    and out of the inbox;
 *  - JMAP: a mailbox there is both — mail can sit in several at once, and
 *    a move replaces them — so every label is in both pickers;
 *  - POP3: nothing on the server to move to; every label is a tag.
 *
 * With no account — the list, whose selection can span several — a label is
 * offered where it is that kind on ANY of the user's accounts, and
 * MoveToService does the right thing for each message's own account.
 *
 * Which labels are folders where comes from the sidebar's one read of the
 * user's folder bindings, and the accounts from UserAccounts, rather than off
 * each label's bindings and the user's accounts: those are lazy collections,
 * and the pickers are on every list page (ThreadListQueryBudgetTest).
 */
final readonly class LabelKinds
{
    public function __construct(
        private SidebarCounts $sidebar,
        private UserAccounts  $accounts,
    ) {
    }

    public function isPlaceOn(Label $label, Account $account): bool
    {
        if (true === $account->isJmap()) {
            return true;
        }

        if (true === $account->isGmail()) {
            return true === $account->gmailMoveOffersLabels;
        }

        return $this->sidebar->isFolderOn($label, $account);
    }

    public function isTagOn(Label $label, Account $account): bool
    {
        if (true === $account->isJmap() || true === $account->isGmail()) {
            return true;
        }

        return false === $this->sidebar->isFolderOn($label, $account);
    }

    /**
     * Whether the sidebar's LABELS section lists this label: a tag on any of
     * the user's accounts, or the parent of one that is — or every label,
     * where the user has folders listed there too (User::$foldersInLabels).
     * A folder that is a tag nowhere is listed under its account instead.
     */
    public function inLabels(Label $label, User $user): bool
    {
        if (true === $user->foldersInLabels) {
            return true;
        }

        foreach ($this->accounts->all($user) as $account) {
            if (true === $this->isTagOn($label, $account)) {
                return true;
            }
        }

        foreach ($this->sidebar->visibleChildrenOf($label) as $child) {
            if (true === $this->inLabels($child, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Label[] $labels
     *
     * @return Label[]
     */
    public function places(array $labels, User $user, ?Account $account = null): array
    {
        return $this->keep($labels, $user, $account, $this->isPlaceOn(...));
    }

    /**
     * @param Label[] $labels
     *
     * @return Label[]
     */
    public function tags(array $labels, User $user, ?Account $account = null): array
    {
        return $this->keep($labels, $user, $account, $this->isTagOn(...));
    }

    /**
     * @param Label[]                         $labels
     * @param callable(Label, Account): bool  $is
     *
     * @return Label[]
     */
    private function keep(array $labels, User $user, ?Account $account, callable $is): array
    {
        $accounts = null !== $account ? [$account] : $this->accounts->all($user);

        return array_values(array_filter(
            $labels,
            static function (Label $label) use ($accounts, $is): bool {
                foreach ($accounts as $candidate) {
                    if (true === $is($label, $candidate)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
