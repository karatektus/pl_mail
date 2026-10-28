<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;

/**
 * Where a label is kept on each of the user's plain IMAP and Microsoft
 * accounts — the indicator in the label dialog (#221 steps 2 and 4).
 *
 * A label is the same label on every account, and on IMAP it can be three
 * different things depending on the account: a folder there (it has a
 * mailbox), a keyword (labels as keywords on, and the server keeps them), or
 * plMail's alone. Said per account, because that is the honest answer — and
 * so that "plMail only" is never a surprise discovered in another mail app.
 *
 * The server's answer is read off the inbox, the one folder every account
 * has; other folders are asked as keywords are written to them.
 */
final readonly class LabelPlaces
{
    public const string FOLDER          = 'folder';
    public const string KEYWORD         = 'keyword';
    public const string KEYWORD_UNASKED = 'keyword_unasked';
    public const string LOCAL_OFF       = 'local_off';
    public const string LOCAL_SERVER    = 'local_server';
    public const string CATEGORY        = 'category';
    public const string CATEGORY_FOLLOW = 'category_followed';

    public function __construct(
        private MailboxRepository $mailboxes,
    ) {
    }

    /**
     * @return list<array{account: Account, state: string}>
     */
    public function of(Label $label, User $user): array
    {
        $places = [];

        foreach ($user->accounts as $account) {
            if (false === $account->isPlainImap() && false === $account->isMicrosoft()) {
                continue;
            }

            $places[] = ['account' => $account, 'state' => $this->stateOn($label, $account)];
        }

        return $places;
    }

    public function stateOn(Label $label, Account $account): string
    {
        if (null !== $label->bindingFor($account)?->mailbox) {
            return self::FOLDER;
        }

        // Microsoft (#221 step 4): a folder there, or a category — every label
        // that is not a folder is pushed as one, and Outlook's changes to it
        // come back here only where the account follows them.
        if (true === $account->isMicrosoft()) {
            if (null !== $label->bindingFor($account)?->graphFolderId) {
                return self::FOLDER;
            }

            return true === $account->followsOutlookCategories ? self::CATEGORY_FOLLOW : self::CATEGORY;
        }

        if (false === $account->labelsAsKeywords) {
            return self::LOCAL_OFF;
        }

        return match ($this->inboxAllows($account)) {
            true    => self::KEYWORD,
            false   => self::LOCAL_SERVER,
            default => self::KEYWORD_UNASKED,
        };
    }

    /** What the inbox's PERMANENTFLAGS said, null when not asked yet. */
    public function inboxAllows(Account $account): ?bool
    {
        return $this->mailboxes->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::INBOX])?->keywordsAllowed;
    }
}
