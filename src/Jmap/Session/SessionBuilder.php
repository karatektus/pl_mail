<?php

declare(strict_types=1);

namespace App\Jmap\Session;

use App\Entity\User\User;
use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Protocol\Capability;
use App\Jmap\State\StateManager;
use App\Service\Calendar\RecurrenceMaterialiser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the JMAP Session object (RFC 8620 §2) returned from /jmap/session and
 * /.well-known/jmap. One JMAP account is exposed per connected mail account, so
 * a single login enumerates all of the user's mail; a unified inbox is a
 * client-side concern (one Email/query per account, merged in the client).
 *
 * NOTE — the ONLY place coupled to the mail-account entity shape. It reads:
 *   User::$accounts: iterable<Account>
 *   Account::$id: ?int
 *   Account::$email: ?string
 * Adjust these three if the shape moves and nothing else changes. They are
 * properties, not accessors — this said getId() and getEmail() long after the
 * getters were gone, which is the one way a note like this can be worse than
 * absent: it is here to be trusted without checking.
 */
final class SessionBuilder
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly CalendarAccountResolver $calendarAccountResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $vapidPublicKey,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(User $user): array
    {
        $apiUrl = $this->urlGenerator->generate('jmap_api', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $base = (string) preg_replace('#/jmap/api$#', '', $apiUrl);

        $accounts = [];
        $primaryId = null;
        // Calendars are the user's, not an account's, so exactly one account
        // publishes them — see CalendarAccountResolver, which is also what
        // every calendar method checks an accountId against. Two rules for
        // which account that is would advertise calendars where they cannot be
        // fetched.
        $calendarAccount = $this->calendarAccountResolver->accountFor($user);
        $calendarAccountId = null === $calendarAccount ? null : (string) $calendarAccount->id;

        foreach ($user->accounts as $account) {
            $accountId = (string) $account->id;
            $primaryId ??= $accountId;

            $accountCapabilities = [
                Capability::MAIL => $this->mailAccountCapabilities(),
                Capability::SUBMISSION => $this->submissionCapabilities(),
            ];

            if ($accountId === $calendarAccountId) {
                $accountCapabilities[Capability::CALENDARS] = $this->calendarAccountCapabilities();
            }

            $accounts[$accountId] = [
                'name' => (string) $account->email,
                'isPersonal' => true,
                'isReadOnly' => false,
                'accountCapabilities' => $accountCapabilities,
            ];
        }

        $primaryAccounts = [];

        if (null !== $primaryId) {
            $primaryAccounts[Capability::MAIL] = $primaryId;
        }

        if (null !== $calendarAccountId) {
            $primaryAccounts[Capability::CALENDARS] = $calendarAccountId;
        }

        // Empty JMAP maps must serialise as {} rather than [].
        $accountsValue = new \stdClass();

        if (count($accounts) > 0) {
            $accountsValue = $accounts;
        }

        $primaryAccountsValue = new \stdClass();

        if (count($primaryAccounts) > 0) {
            $primaryAccountsValue = $primaryAccounts;
        }

        return [
            'capabilities' => [
                Capability::CORE => $this->coreCapabilities(),
                Capability::MAIL => new \stdClass(),
                Capability::SUBMISSION => new \stdClass(),
                Capability::PUSH => $this->pushCapabilities(),
                Capability::CALENDARS => new \stdClass(),
            ],
            'accounts' => $accountsValue,
            'primaryAccounts' => $primaryAccountsValue,
            'username' => $user->getUserIdentifier(),
            'apiUrl' => $apiUrl,
            'downloadUrl' => $base.'/jmap/download/{accountId}/{blobId}/{name}?accept={type}',
            'uploadUrl' => $base.'/jmap/upload/{accountId}',
            'eventSourceUrl' => $base.'/jmap/eventsource?types={types}&closeafter={closeafter}&ping={ping}',
            'state' => $this->stateManager->sessionState($user),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function coreCapabilities(): array
    {
        return [
            'maxSizeUpload' => 50_000_000,
            'maxConcurrentUpload' => 4,
            'maxSizeRequestObject' => 10_000_000,
            'maxConcurrentRequests' => 4,
            'maxCallsInRequest' => 32,
            'maxObjectsInGet' => 500,
            'maxObjectsInSet' => 500,
            'collationAlgorithms' => [
                'i;ascii-numeric',
                'i;ascii-casemap',
                'i;unicode-casemap',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mailAccountCapabilities(): array
    {
        return [
            'maxMailboxesPerEmail' => null,
            'maxMailboxDepth' => null,
            'maxSizeMailboxName' => 255,
            'maxSizeAttachmentsPerEmail' => 50_000_000,
            'emailQuerySortOptions' => ['receivedAt', 'from', 'to', 'subject', 'size'],
            'mayCreateTopLevelMailbox' => true,
        ];
    }

    /**
     * What a client may assume about this account's calendars.
     *
     * maxEventsInGet is lower than the Session's global maxObjectsInGet on
     * purpose and is stated here for that reason: CalendarEvent/get resolves
     * one id at a time, because the ownership-scoped lookup it uses is the only
     * one CalendarEventRepository offers, and a client obeying 500 would
     * otherwise meet a requestTooLarge it was told not to expect.
     *
     * mayCreateCalendar is false because Calendar/set is not implemented: the
     * two provisioned roles are created by CalendarProvisioner and a subscribed
     * one by the subscribe flow, neither of which a JMAP create could stand in
     * for.
     *
     * @return array<string,mixed>
     */
    private function calendarAccountCapabilities(): array
    {
        return [
            'maxEventsInGet' => 100,
            'maxEventsInSet' => 500,
            'mayCreateCalendar' => false,
            // The window CalendarEvent/query can be trusted over. Occurrences
            // are materialised to RecurrenceMaterialiser's horizon and no
            // further, so a query outside it answers from a partial index —
            // stated rather than left for a client to discover as a recurring
            // meeting that stops.
            'materialisedHorizon' => [
                'past' => RecurrenceMaterialiser::HORIZON_PAST,
                'future' => RecurrenceMaterialiser::HORIZON_FUTURE,
            ],
        ];
    }

    /**
     * The VAPID public key clients pass as applicationServerKey when creating
     * a browser push subscription. Empty when Web Push is unconfigured, which
     * is a client's signal not to offer push at all.
     *
     * @return array<string,mixed>
     */
    private function pushCapabilities(): array
    {
        return ['vapidPublicKey' => $this->vapidPublicKey];
    }

    /**
     * Sending is queued on the messenger bus with no scheduling support, so
     * there is no future-send window to advertise.
     *
     * @return array<string,mixed>
     */
    private function submissionCapabilities(): array
    {
        return [
            'maxDelayedSend' => 0,
            'submissionExtensions' => new \stdClass(),
        ];
    }
}
