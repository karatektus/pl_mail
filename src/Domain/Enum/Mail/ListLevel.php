<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

use App\Entity\User\User;

/**
 * What one row of a mail list is (#615): a conversation, or a single email.
 *
 * The person's "Conversation view" switch picks it. Every list is defined
 * once (MailListQuery) and built at either level, so the two views cannot
 * come to disagree about what Inbox, Starred or a label holds — only about
 * how it is grouped.
 */
enum ListLevel: string
{
    case Conversation = 'conversation';

    case Email = 'email';

    public static function of(User $user): self
    {
        return true === $user->conversationView ? self::Conversation : self::Email;
    }
}
