<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;

/**
 * Where a set of remote calendars is reached from — one mail account, or one
 * connection.
 *
 * Discovery happens before any Calendar row exists: the user has just connected
 * a Google account or a CalDAV server and the question is "what calendars are
 * there?". There is nothing to hand a driver at that point except the thing
 * holding the credentials, and the two kinds of holder are genuinely different
 * entities — Google and Microsoft ride the existing mail OAuth grant on
 * Account, CalDAV is an Integration. So this carries either, and exactly one.
 *
 * A union of two nullable fields rather than two interfaces or a common
 * "credential holder" base. Account and Integration have nothing else in common
 * and are not going to grow it: one is a mailbox with a token trio on it, the
 * other is a URL and an app password. An interface over the pair would be an
 * interface with no methods, which is a comment pretending to be a type.
 *
 * Constructed only through the two named constructors, so the "exactly one" is
 * structural rather than a rule in prose that a caller has to remember.
 */
final readonly class CalendarSource
{
    private function __construct(
        public ?Account     $account,
        public ?Integration $integration,
    ) {
    }

    /** Google or Microsoft: calendar access rides the mail grant. */
    public static function ofAccount(Account $account): self
    {
        return new self($account, null);
    }

    /** CalDAV and anything else that is its own connection. */
    public static function ofIntegration(Integration $integration): self
    {
        return new self(null, $integration);
    }

    /**
     * The source a calendar already bound to a remote came from.
     *
     * Returns null for a calendar that mirrors nothing — a hand-made one, the
     * user's default (which is where extraction writes), or a per-account one.
     * A caller that has one of those and asks for a driver has made a mistake,
     * and a null here is what lets the sync service say so instead of
     * guessing.
     */
    public static function ofCalendar(Calendar $calendar): ?self
    {
        if (null !== $calendar->integration) {
            return self::ofIntegration($calendar->integration);
        }

        if (null !== $calendar->account) {
            return self::ofAccount($calendar->account);
        }

        return null;
    }

    /**
     * Which cloud mail provider this is, or null when the source is a
     * connection rather than an account — and also null for an account that
     * authenticates with a password, which is an IMAP mailbox with no calendar
     * API behind it at all.
     *
     * This is the question a Google or Microsoft driver's supports() asks.
     */
    public function mailProvider(): ?MailProvider
    {
        if (null === $this->account || AuthType::OAuth2->value !== $this->account->authType) {
            return null;
        }

        // tryFrom rather than from: $oauthProvider is a plain string column and
        // an install that once held a provider plMail no longer knows about
        // must answer "not one of mine", not throw inside a driver's
        // supports() and take the whole sync sweep with it.
        return MailProvider::tryFrom((string) $this->account->oauthProvider);
    }

    /**
     * Which connected service this is, or null when the source is an account.
     * The question a CalDAV driver's supports() asks.
     */
    public function integrationProvider(): ?Provider
    {
        return $this->integration?->provider;
    }

    /**
     * The person these calendars belong to.
     *
     * Stays a method rather than a third property: it is read off whichever
     * holder is present, and storing a copy would create a second answer that
     * can disagree with the first.
     */
    public function user(): ?User
    {
        if (null !== $this->account) {
            return $this->account->usr;
        }

        return $this->integration?->usr;
    }
}
