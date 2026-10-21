<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Mirror a Google or Microsoft account's address books, the first time its
 * grant carries the contacts scope. See OAuthAccountLinker::bringContacts().
 */
final readonly class SubscribeAccountContactsMessage
{
    public function __construct(
        public int $accountId,
    ) {}
}
