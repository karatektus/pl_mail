<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;

/**
 * What the All Mail view holds, asked by everything that acts on its rows.
 *
 * All Mail is every conversation outside Spam and the bin — across the
 * reader's active accounts, or within one of them. Nothing wears it, so
 * "is this row still in the list" cannot be answered the way it is for a
 * label view, by looking at the thread's labels. It is answered here instead,
 * once, for the four callers that need it: the archive and snooze buttons on
 * a row (ThreadStatusController), the same over a selection
 * (BulkStatusController), Move to (MoveToService::staysInView()) and "select
 * all" (ListViewResolver).
 *
 * The list names itself the way every list does, with the frame's scope and
 * value: the scope is the role's own value, `all_mail`, and the value is an
 * account id for one account's list or empty for everybody's. One scope
 * rather than two, so that a role lookup on the scope (LabelRole::tryFrom)
 * finds the view instead of missing a second spelling of it.
 *
 * Both arrive from the browser. They only ever decide how a row is drawn
 * after an action the caller has already authorised, and an account id that
 * is not the reader's names a list they cannot have, so it holds nothing.
 */
final readonly class AllMailView
{
    public const string SCOPE = LabelRole::AllMail->value;

    public function __construct(
        private AccountRepository $accounts,
    ) {
    }

    /**
     * Whether a list scope names this view.
     */
    public static function names(string $scope): bool
    {
        return self::SCOPE === $scope;
    }

    /**
     * Whether a value names the list across every account, rather than one
     * account's.
     */
    public static function isUnified(string $value): bool
    {
        return '' === $value;
    }

    /**
     * The account a value narrows the view to.
     *
     * Null for the unified list. False for a value that names no account of
     * this reader's — malformed, gone, or somebody else's — which is a list
     * with nothing in it, not an error: the value came from a page, and the
     * page may be older than the account.
     */
    public function account(User $user, string $value): Account|false|null
    {
        if (true === self::isUnified($value)) {
            return null;
        }

        if (false === ctype_digit($value)) {
            return false;
        }

        $account = $this->accounts->find((int) $value);

        if (null === $account || $account->usr?->id !== $user->id) {
            return false;
        }

        return $account;
    }

    /**
     * Whether a conversation belongs in the list a value names, as it is now.
     *
     * Asked after an action, so the answer is about what was written: a
     * conversation archived or snoozed is still here, one moved to Spam or the
     * bin is not. The unified list leaves out switched-off accounts, as its
     * query does (MessageThreadRepository::allMailQuery()).
     */
    public function holds(MessageThread $thread, User $user, string $value): bool
    {
        $account = $thread->account;

        if (null === $account || $account->usr?->id !== $user->id) {
            return false;
        }

        $narrowedTo = $this->account($user, $value);

        if (false === $narrowedTo) {
            return false;
        }

        if (null === $narrowedTo && true !== $account->isActive) {
            return false;
        }

        if (null !== $narrowedTo && $narrowedTo->id !== $account->id) {
            return false;
        }

        foreach ($thread->labels as $label) {
            if (LabelRole::Trash === $label->role || LabelRole::Spam === $label->role) {
                return false;
            }
        }

        return true;
    }
}
