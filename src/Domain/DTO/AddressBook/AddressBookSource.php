<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;

/**
 * Where a set of remote address books is reached from — one mail account, or
 * one connection. CalendarSource's counterpart, for the same reason: Google
 * and Microsoft ride the mail account's OAuth grant, CardDAV is its own
 * connection, and discovery happens before any AddressBook row exists.
 *
 * Exactly one of the two is set, which the named constructors make structural.
 *
 * @see \App\Domain\DTO\Calendar\CalendarSource
 */
final readonly class AddressBookSource
{
    private function __construct(
        public ?Account     $account,
        public ?Integration $integration,
    ) {
    }

    /** Google or Microsoft: contacts ride the mail grant. */
    public static function ofAccount(Account $account): self
    {
        return new self($account, null);
    }

    /** CardDAV, or a Nextcloud connection. */
    public static function ofIntegration(Integration $integration): self
    {
        return new self(null, $integration);
    }

    /**
     * Which OAuth provider is behind the account, or null for a connection or
     * a password account. What a Google or Graph driver's supports() asks; see
     * CalendarSource::mailProvider(), which this answers the same way.
     */
    public function mailProvider(): ?MailProvider
    {
        if (null === $this->account || AuthType::OAuth2->value !== $this->account->authType) {
            return null;
        }

        return MailProvider::tryFrom((string) $this->account->oauthProvider);
    }

    /** Which connected service this is, or null when the source is an account. */
    public function integrationProvider(): ?Provider
    {
        return $this->integration?->provider;
    }

    public function user(): ?User
    {
        return $this->account->usr ?? $this->integration?->usr;
    }
}
