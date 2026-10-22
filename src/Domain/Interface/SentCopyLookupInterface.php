<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Entity\Mail\Account;

/**
 * A sender whose provider can be asked whether a mail it was given went out.
 *
 * Gmail and Graph file their own Sent copy, and the copy carries the
 * Message-ID plMail stamped before sending, so a lost answer can be settled by
 * looking for it. SMTP cannot be asked: plMail files that Sent copy itself,
 * after the send, so its presence proves nothing.
 */
interface SentCopyLookupInterface
{
    /**
     * Whether a message with this Message-ID (bare, without angle brackets) is
     * in the account's Sent mail.
     *
     * @throws \Throwable when the provider cannot be asked; the caller then
     *                    does not know, and must not send again
     */
    public function isInSent(Account $account, string $messageId): bool;
}
