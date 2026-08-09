<?php

declare(strict_types=1);

namespace App\Jmap\Session;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\BackgroundPreset;
use App\Domain\Enum\Theme\Density;
use App\Domain\Enum\Theme\Layout;
use App\Domain\Enum\Theme\Theme;
use App\Entity\Embeddable\Appearance;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Mail\SubmissionEnvelope;
use App\Jmap\Mapper\AppearanceMapper;
use App\Jmap\Method\Contact\ContactAutocompleteMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Push\FcmSettings;
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
 *   Account::$backfillTarget: ?int — how much of the mailbox is in
 *   Account::needsBackfill(): bool
 * Adjust these if the shape moves and nothing else changes. They are
 * properties, not accessors — this said getId() and getEmail() long after the
 * getters were gone, which is the one way a note like this can be worse than
 * absent: it is here to be trusted without checking. $backfillTarget is a
 * virtual property over the `settings` JSONB bag rather than a column, which
 * changes nothing here and everything in a migration.
 */
final class SessionBuilder
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly CalendarAccountResolver $calendarAccountResolver,
        private readonly AppearanceMapper $appearanceMapper,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly FcmSettings $fcmSettings,
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
                Capability::SYNC => $this->syncAccountCapabilities($account),
                // On every account, unlike calendars below. The address book is
                // the user's, and Contact/autocomplete returns no ids for a
                // client to key by (accountId, id) — so there is nothing here
                // to draw once per account, and a client composing from the
                // second account can ask the account it is composing from.
                Capability::CONTACTS => new \stdClass(),
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
            $primaryAccounts[Capability::CONTACTS] = $primaryId;
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
                Capability::APPEARANCE => $this->appearanceCapabilities($user),
                // Empty at the top level on purpose: backfill progress is a
                // property of one account, and every value lives in that
                // account's entry under the same URN.
                Capability::SYNC => new \stdClass(),
                Capability::CONTACTS => $this->contactsCapabilities(),
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
     * How much of this account the server has actually got hold of, so a client
     * can say *why* a mail the user remembers is not in a search result.
     *
     * The server intends to hold everything — there is no retention setting any
     * more — so the only honest gap left is a backfill that has not finished
     * walking the mailbox yet. That is worth publishing on its own: without it,
     * mail the server has not fetched yet and mail the phone has not caught up
     * on look identical from the phone (an empty result) and they want opposite
     * reactions. The numbers are:
     *
     *  - `backfillTarget` — how far back a *completed* backfill actually
     *    reached: 0 for the whole mailbox, null when none has finished yet. A
     *    positive number is a stopping point left over from the retired sync
     *    cap, which is unfinished rather than complete.
     *  - `backfillPending` — whether anything is still owed. Account::
     *    needsBackfill() decides it, so it cannot disagree with the sync
     *    engine's own decision to keep walking back. It is NOT "a backfill is
     *    running this second" — nothing records that, and a client would read a
     *    running/not-running flag as progress.
     *
     * @return array<string,mixed>
     */
    private function syncAccountCapabilities(Account $account): array
    {
        return [
            'backfillTarget' => $account->backfillTarget,
            'backfillPending' => $account->needsBackfill(),
        ];
    }

    /**
     * The user's chosen appearance, plus the vocabulary it is drawn from.
     *
     * Per user, not per account — which is why it sits in the Session's
     * top-level capabilities rather than in accountCapabilities beside the
     * backfill numbers. A user has one theme and three mailboxes.
     *
     * The current values are a *hint*, and the compact subset for that reason:
     * the Session's `state` is a hash of the user's account ids, so it does
     * not move when a theme changes and a client holding an old Session holds
     * an old theme. Appearance/get is the authoritative read. What is here is
     * enough to paint the chrome on the first frame instead of flashing the
     * wrong palette while the first API call is in flight.
     *
     * The vocabularies and ranges are published because Appearance/set refuses
     * everything outside them. A closed vocabulary a client can only discover
     * by being refused is a client that ships a theme picker with a broken
     * entry; `layoutDefaults` is the knob preset each layout seeds, so a
     * client's sliders can sit where the web pane's do.
     *
     * @return array<string,mixed>
     */
    private function appearanceCapabilities(User $user): array
    {
        return [
            'appearance' => $this->appearanceMapper->compact($user->appearance),
            'themes' => array_column(Theme::cases(), 'value'),
            'layouts' => array_column(Layout::cases(), 'value'),
            'densities' => array_column(Density::cases(), 'value'),
            'backgroundKinds' => array_column(BackgroundKind::cases(), 'value'),
            'backgroundPresets' => array_column(BackgroundPreset::cases(), 'value'),
            'layoutDefaults' => array_combine(
                array_column(Layout::cases(), 'value'),
                array_map(static fn (Layout $layout): array => $layout->defaults(), Layout::cases()),
            ),
            // The clamps in Appearance's setters, read off the setters' own
            // constants and stated rather than discovered: a value outside
            // these is stored at the nearest end and reported back in
            // Appearance/set's `updated` map.
            'ranges' => [
                'paneAlpha' => Appearance::RANGE_PANE_ALPHA,
                'paneBlur' => Appearance::RANGE_PANE_BLUR,
                'radius' => Appearance::RANGE_RADIUS,
                'scrimAlpha' => Appearance::RANGE_SCRIM_ALPHA,
                'mainAlpha' => Appearance::RANGE_MAIN_ALPHA,
            ],
        ];
    }

    /**
     * The two numbers Contact/autocomplete would otherwise make a client
     * discover: what it gets when it asks for nothing, and the point past which
     * a larger "limit" stops making the list longer. Server-wide rather than
     * per-account, because the address book is the user's and the cap is the
     * method's.
     *
     * Read off the method so there is one place per fact — the same reason the
     * calendar block below reads RecurrenceMaterialiser's horizon rather than
     * repeating it.
     *
     * @return array<string,mixed>
     */
    private function contactsCapabilities(): array
    {
        return [
            'maxSuggestions' => ContactAutocompleteMethod::MAX_LIMIT,
            'defaultSuggestions' => ContactAutocompleteMethod::DEFAULT_LIMIT,
        ];
    }

    /**
     * Which push transports this installation can actually deliver over.
     *
     * `vapidPublicKey` is what clients pass as applicationServerKey when
     * creating a browser push subscription; empty means Web Push is
     * unconfigured. `fcm` is the same signal for Firebase.
     *
     * **`fcm` is advertised even when false**, so a client can tell "this
     * server does not do FCM" from "this server is too old to know what FCM
     * is". A missing key would make those two indistinguishable, and the
     * sensible reaction to each is the opposite one.
     *
     * **`fcmConfig` is absent rather than null when FCM is off**, which is the
     * opposite rule and deliberate. `fcm` is a question every client asks, so it
     * always has an answer; fcmConfig is a set of values that either exist or do
     * not, and a null-valued object invites a client to read `.projectId` off it
     * and get null rather than to check first. Absence cannot be dereferenced.
     *
     * What it carries is the exact input to Android's FirebaseOptions.Builder,
     * and it is published because the plMail app ships as ONE APK from the Play
     * Store while every install has its own Firebase project — so the app cannot
     * bake in a google-services.json and initialises from this instead. All four
     * values ship inside every Firebase APK and are public by nature; the
     * service-account key that can actually send is encrypted and stays here.
     *
     * @return array<string,mixed>
     */
    private function pushCapabilities(): array
    {
        $capabilities = [
            'vapidPublicKey' => $this->vapidPublicKey,
            'fcm'            => $this->fcmSettings->isActive(),
        ];

        $client = $this->fcmSettings->clientConfig();

        if (null !== $client) {
            $capabilities['fcmConfig'] = [
                'projectId'     => $client->projectId,
                'applicationId' => $client->applicationId,
                'apiKey'        => $client->apiKey,
                'senderId'      => $client->senderId,
            ];
        }

        return $capabilities;
    }

    /**
     * What a client may ask for when it sends, and how far ahead.
     *
     * maxDelayedSend was 0 — "this server does not do delayed send" — and is
     * now the real ceiling EmailSubmission/set enforces, read off the same
     * constant so the advertised number and the refusal can never disagree.
     *
     * The extension is advertised the way RFC 8621 §7 has it: FUTURERELEASE
     * (RFC 4865) with the two parameters that carry the request. It is not a
     * relay capability plMail discovered — nothing here speaks ESMTP to
     * announce it — but the hold is genuinely honoured, by keeping the
     * messenger envelope until the release time, and this is the vocabulary
     * the spec gives a client for asking.
     *
     * @return array<string,mixed>
     */
    private function submissionCapabilities(): array
    {
        return [
            'maxDelayedSend' => SubmissionEnvelope::MAX_HOLD_SECONDS,
            'submissionExtensions' => ['FUTURERELEASE' => ['HOLDFOR', 'HOLDUNTIL']],
        ];
    }
}
