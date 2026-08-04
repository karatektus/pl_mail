<?php

declare(strict_types=1);

namespace App\Service\Calendar\Subscription;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Integration\ServiceKind;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\AccountRepository;

/**
 * Everything a user could subscribe calendars from.
 *
 * Two populations that look like one on screen and are nothing alike
 * underneath: mail accounts whose provider carries calendars on the grant they
 * already hold, and connections made for calendars alone. The settings screen
 * lists both under one heading because to a person they are the same thing —
 * somewhere calendars come from — and the difference only shows in what
 * connecting one costs.
 *
 * Which accounts qualify is asked of the driver registry rather than decided
 * here. A list built from "isGmail() or isMicrosoft()" would be a third copy of
 * a fact CalendarSource::mailProvider() and each driver's supports() already
 * hold, and the copy that drifts is always the one in the UI: an IMAP account
 * would keep offering a "find my calendars" button that answers "no calendar
 * service is configured for this account".
 */
final readonly class CalendarSourceLister
{
    public function __construct(
        private AccountRepository     $accounts,
        private IntegrationRepository $integrations,
        private CalendarDiscoverer    $discoverer,
    ) {
    }

    /**
     * Mail accounts with a calendar service behind them.
     *
     * @return list<Account>
     */
    public function accountsFor(User $user): array
    {
        return array_values(array_filter(
            $this->accounts->findForUserOrderedByName($user),
            fn (Account $account): bool => $this->discoverer->supports(CalendarSource::ofAccount($account)),
        ));
    }

    /**
     * Connections made for calendars — today that means CalDAV, and the filter
     * is on ServiceKind rather than on the CalDav case so a second calendar
     * protocol appears here by declaring its kind and nothing else.
     *
     * @return list<Integration>
     */
    public function connectionsFor(User $user): array
    {
        return array_values(array_filter(
            $this->integrations->findForUserOrdered($user),
            static fn (Integration $integration): bool => ServiceKind::Calendar === $integration->provider->kind(),
        ));
    }
}
