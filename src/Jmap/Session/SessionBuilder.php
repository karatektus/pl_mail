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
 *   Account::$syncLimit: int, Account::$backfillTarget: ?int — the sync window
 *   Account::needsBackfill(), Account::supportsSyncLimit(): bool
 * Adjust these if the shape moves and nothing else changes. They are
 * properties, not accessors — this said getId() and getEmail() long after the
 * getters were gone, which is the one way a note like this can be worse than
 * absent: it is here to be trusted without checking. The last four are virtual
 * properties over the `settings` JSONB bag rather than columns, which changes
 * nothing here and everything in a migration.
 */
final class SessionBuilder
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly CalendarAccountResolver $calendarAccountResolver,
        private readonly AppearanceMapper $appearanceMapper,
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
                Capability::SYNC => $this->syncAccountCapabilities($account),
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
                Capability::APPEARANCE => $this->appearanceCapabilities($user),
                // Empty at the top level on purpose: the sync window is a
                // property of one account, and every value lives in that
                // account's entry under the same URN.
                Capability::SYNC => new \stdClass(),
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
     * The sync window this account is actually holding, so a client can say
     * *why* a mail the user remembers is not in a search result.
     *
     * Without it the two cases are indistinguishable from the phone: mail the
     * server never fetched and mail the phone has not caught up on look
     * identical — an empty result — and they want opposite reactions ("raise
     * the limit in settings" versus "wait"). The numbers are:
     *
     *  - `syncLimit` — the newest-N cap in force, 0 for no cap. Reported as 0
     *    on Microsoft accounts whatever the stored setting says, because Graph
     *    cannot honour it (Account::supportsSyncLimit) and the cap in force is
     *    the only honest thing to publish. Reporting the stored number would
     *    have a client explain a gap that is not there.
     *  - `backfillTarget` — how far back a *completed* backfill actually
     *    reached: 0 for the whole mailbox, null when none has finished yet.
     *    Distinct from the cap on purpose; the gap between the two is the mail
     *    that is coming but not here.
     *  - `backfillPending` — whether that gap is non-empty. Derived from the
     *    same two values by Account::needsBackfill(), so it cannot disagree
     *    with the sync engine's own decision to keep walking back. It is NOT
     *    "a backfill is running this second" — nothing records that, and a
     *    client would read a running/not-running flag as progress.
     *
     * @return array<string,mixed>
     */
    private function syncAccountCapabilities(Account $account): array
    {
        return [
            'syncLimit' => true === $account->supportsSyncLimit() ? $account->syncLimit : 0,
            'backfillTarget' => $account->backfillTarget,
            'backfillPending' => $account->needsBackfill(),
        ];
    }

    /**
     * The user's chosen appearance, plus the vocabulary it is drawn from.
     *
     * Per user, not per account — which is why it sits in the Session's
     * top-level capabilities rather than in accountCapabilities beside the
     * sync window. A user has one theme and three mailboxes.
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
