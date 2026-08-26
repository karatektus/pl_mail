<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Graph;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\InstanceOverride;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\DTO\Calendar\RemoteInstance;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\OAuthGrantRevokedException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Service\Calendar\RecurrenceMaterialiser;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The calendars behind a Microsoft mail account, over Microsoft Graph.
 *
 * Rides the mail account's existing OAuth grant. MailProvider::scopes() already
 * asks for Calendars.ReadWrite on the same consent as Mail.ReadWrite and
 * OAuthTokenManager already keeps the token alive, so there is no second
 * connection, no second consent screen and no credential of its own here —
 * which is also why supports() answers on the account's provider and nothing
 * else.
 *
 * ── Why calendarView/delta, and what it costs ──────────────────────────────
 *
 * Graph offers delta on exactly one calendar surface: `calendarView`. There is
 * no `events/delta`. So the choice is not between two delta feeds, it is between
 * a delta over a bounded window and re-listing `/me/calendars/{id}/events` in
 * full on every poll — and a full listing every fifteen minutes, forever, with
 * no way to learn about a deletion except by comparing the whole set, is not a
 * sync. The window wins.
 *
 * The window is RecurrenceMaterialiser's own horizon, read from its constants
 * rather than copied: one year back, two years forward. Those are the bounds
 * plMail materialises occurrences into, so pulling a wider window would fetch
 * events that could never be drawn, and a narrower one would leave gaps inside
 * a range the UI happily scrolls to. The two numbers have to agree, and the way
 * to make them agree is to have one of them.
 *
 * **The cost is that calendarView expands recurrences, and this driver has to
 * undo that.** A weekly meeting inside the window arrives as fifty-odd entries
 * with `type: occurrence` and a `seriesMasterId`, and the engine wants none of
 * them: CalendarEvent stores one row per series and RecurrenceMaterialiser
 * writes the occurrences itself from the rule. Letting the expansion through
 * would put fifty rows in calendar_event, fifty UIDs that no other client shares,
 * and fifty pushes back at Graph the first time somebody edited "the meeting".
 * So an occurrence is not an event here: it is a *mention of a series*, and the
 * master is fetched once per series and emitted once.
 *
 * An `exception` entry — an instance somebody moved — is a mention of the series
 * *and* a fact about that one instance, so it is emitted as an override on it:
 * `originalStart` is the key JSCalendar files the patch under, and the engine
 * puts it in the master's recurrenceOverrides. Collapsed onto the series, which
 * is what happened until this was written, the instance went on being drawn at
 * the time it was moved away from.
 *
 * **A cancelled instance is recognised by what an earlier window wrote down.**
 * Graph reports an occurrence somebody deleted as a `@removed` entry carrying an
 * id and nothing else — not its series, not the start it had — and the resource
 * it names is gone, so it cannot be asked. It is answerable only from a record
 * made while the occurrence still existed, which is why every occurrence and
 * exception this window mentions is also reported as a RemoteInstance, changed
 * or not: the engine keeps them in CalendarEvent::$remoteInstances and reads the
 * tombstone against them. Emitted as a bare tombstone, which is all this driver
 * could say before, it matched no row and did nothing at all — an instance has
 * never been one — and the occurrence removed in Outlook was drawn forever.
 *
 * **A push carries the instances too.** The series' PATCH is the pattern, and an
 * occurrence that differs from the pattern has no home in it, so each stored
 * recurrenceOverride is a second write against the occurrence resource it names
 * — see pushInstances(). Written only as a series update, which is what this
 * driver did until then, a moved or cancelled instance never left plMail.
 *
 * A calendarView delta also means a full read is authoritative only inside the
 * window. An event that has aged out of the past horizon is absent from the
 * listing and CalendarPuller removes its row — which is the wanted answer, since
 * its occurrences would have been dropped by the materialiser on the same run.
 *
 * ── No $select, no Prefer: IdType ─────────────────────────────────────────
 *
 * Both are deliberate absences. A `$select` on a delta call sticks for the whole
 * chain and cannot be changed without restarting it, and one property Graph
 * refuses to project takes the entire request with it — that is exactly how
 * `meetingMessageType` stopped Outlook mailboxes syncing at all (see
 * GraphApiClient::INVITE_SELECT). The whole resource is fetched instead, which
 * costs bandwidth and can never cost a calendar.
 *
 * `Prefer: IdType="ImmutableId"` is what GraphApiClient sends for messages,
 * because a message id changes when it is filed. An event id changes when the
 * event moves between calendars — and this driver syncs one calendar at a time,
 * so an event that leaves is a deletion here and a creation over there whichever
 * id scheme is in force. Sending the header would buy nothing and would have to
 * be sent identically on every leg of a delta chain that outlives this process.
 *
 * ── Failure ───────────────────────────────────────────────────────────────
 *
 * Classified by what a worker should do with it, per CalendarSyncException:
 *
 *   410 (resyncRequired, syncStateNotFound) and 412  → resync
 *   429, 503 with Retry-After                        → throttled
 *   401, 403 naming an authorization failure         → permanent, actionably worded
 *   400 and 404                                      → permanent
 *   403 saying anything else, 5xx, transport, bad JSON → unclassified
 *
 * The 403 split is the one that matters. CalendarSyncPermanentException is a
 * decision never to try again, and Graph answers 403 both for a grant with no
 * calendar scope and for a handful of tenant-level refusals that clear. Only a
 * body that names an authorization code is written off; anything else raises the
 * base class and lets the transport's own strategy decide, which is what that
 * exception's docblock asks for.
 *
 * There is no GraphCalendarClient beside this the way GraphApiClient sits beside
 * the mail syncers, and that is a decision rather than an omission. GraphApiClient
 * exists because five callers share it; here there is one, the whole point of
 * the interface is that HTTP stops at this line, and a client class would be a
 * class whose only job is to re-throw somebody else's exception as ours.
 */
final readonly class GraphCalendarSyncDriver implements CalendarSyncDriverInterface
{
    private const string ME = 'https://graph.microsoft.com/v1.0/me';

    /**
     * Graph error codes that mean the grant, not the request, is the problem.
     *
     * Lower-cased for comparison because Graph is inconsistent about it across
     * the Outlook-derived (`ErrorAccessDenied`) and directory-derived
     * (`Authorization_RequestDenied`) halves of its error vocabulary.
     *
     * @var list<string>
     */
    private const array AUTHORIZATION_CODES = [
        'erroraccessdenied',
        'accessdenied',
        'authorization_requestdenied',
        'invalidauthenticationtoken',
        'insufficientscope',
        'unauthorizedaccess',
    ];

    /**
     * What a user is told when Microsoft refuses on authorization grounds.
     *
     * Names the action rather than the status, because this string lands in
     * Calendar::$lastSyncError and is rendered in the calendar settings list:
     * "403" tells the person looking at it nothing they can do.
     */
    private const string RECONNECT_HINT = 'Microsoft refused calendar access for this account. '
        . 'Reconnect the account and allow calendar permissions.';

    public function __construct(
        private HttpClientInterface $httpClient,
        private OAuthTokenManager   $tokens,
        private GraphEventMapper    $mapper,
        private GraphTimeZoneMapper $zones,
        private LoggerInterface     $logger,
    ) {
    }

    public function supports(CalendarSource $source): bool
    {
        return MailProvider::Microsoft === $source->mailProvider();
    }

    /**
     * @return list<RemoteCalendar>
     */
    public function discover(CalendarSource $source): array
    {
        $account  = $this->accountOf($source);
        $timeZone = $this->mailboxTimeZone($account);

        $calendars = [];

        foreach ($this->collect($account, self::ME . '/calendars', [], 'listing the calendars')['items'] as $calendar) {
            $remoteId = trim((string) ($calendar['id'] ?? ''));

            if ('' === $remoteId) {
                continue;
            }

            $calendars[] = new RemoteCalendar(
                remoteId:   $remoteId,
                name:       trim((string) ($calendar['name'] ?? '')),
                color:      $this->hexColourOf($calendar),
                timeZone:   $timeZone,
                // Absent means editable. /me/calendars lists the mailbox's own
                // calendars and the ones shared into it, and Graph states
                // canEdit on every one — but defaulting the other way would stop
                // every push on a mailbox that ever omitted it, silently, and a
                // refused write is a visible error where a skipped one is not.
                isReadOnly: false === ($calendar['canEdit'] ?? true),
                isPrimary:  true === ($calendar['isDefaultCalendar'] ?? false),
            );
        }

        return $calendars;
    }

    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet
    {
        $account = $this->accountOfCalendar($calendar);

        // Asked on both paths although only one uses it: a calendar with no
        // remote id behind it is a wiring fault, and discovering that only when
        // its stored token eventually expires would blame the token.
        $remoteId = $this->remoteIdOf($calendar);

        $isIncremental = null !== $syncToken && '' !== $syncToken;

        if (true === $isIncremental) {
            // A deltaLink carries every parameter it needs, including the window
            // the chain was opened with. Re-sending the window would either be
            // ignored or restart the enumeration.
            $url   = (string) $syncToken;
            $query = [];
        } else {
            $url   = sprintf('%s/calendars/%s/calendarView/delta', self::ME, rawurlencode($remoteId));
            $query = $this->window();
        }

        $page   = $this->collect($account, $url, $query, 'reading calendar changes');
        $window = $this->eventsIn($account, $page['items'], $isIncremental);

        return new CalendarChangeSet(
            events:        $window['events'],
            nextSyncToken: $page['deltaLink'],
            instances:     $window['instances'],
        );
    }

    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult
    {
        $account = $this->accountOfCalendar($calendar);
        $body    = $this->mapper->toGraphEvent($event);

        if (null === $event->remoteId) {
            $created = $this->call(
                $account,
                'POST',
                sprintf('%s/calendars/%s/events', self::ME, rawurlencode($this->remoteIdOf($calendar))),
                ['json' => $body],
                'creating an event',
            );

            $result = $this->writeResult($created, null);

            $this->pushInstances($account, $event, $result->remoteId);

            return $result;
        }

        $options = ['json' => $body];

        // Without If-Match the update lands on whatever revision is there now,
        // discarding a change made in Outlook between this run's pull and this
        // write. With it, Graph answers 412 and the engine re-reads instead.
        // An event with no stored etag is one the remote has never versioned for
        // us, and there is nothing to condition on.
        if (null !== $event->remoteEtag) {
            $options['headers'] = ['If-Match' => $event->remoteEtag];
        }

        $updated = $this->call(
            $account,
            'PATCH',
            sprintf('%s/events/%s', self::ME, rawurlencode($event->remoteId)),
            $options,
            'updating an event',
        );

        $result = $this->writeResult($updated, $event->remoteId);

        $this->pushInstances($account, $event, $result->remoteId);

        return $result;
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $remoteId = $event->remoteId;

        if (null === $remoteId) {
            return;
        }

        // 404 and 410 are successes here, per the interface: every provider
        // answers one of them to the second delete, the engine retries jobs, and
        // treating it as a failure leaves the row in PendingDelete forever.
        $this->call(
            $this->accountOfCalendar($calendar),
            'DELETE',
            sprintf('%s/events/%s', self::ME, rawurlencode($remoteId)),
            [],
            'deleting an event',
            [404, 410],
        );
    }

    // ── Pulling ──────────────────────────────────────────────────────────────

    /**
     * The window a first delta call opens, in the bounds plMail can actually
     * draw. See the class docblock.
     *
     * @return array<string,string>
     */
    private function window(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            'startDateTime' => $now->modify(RecurrenceMaterialiser::HORIZON_PAST)->format('Y-m-d\TH:i:s\Z'),
            'endDateTime'   => $now->modify(RecurrenceMaterialiser::HORIZON_FUTURE)->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * One window of calendarView entries as the events the engine stores, and as
     * the record of what its instance ids mean.
     *
     * @param list<array<string,mixed>> $items
     * @param bool                      $isIncremental false for a full read, where
     *                                                 a tombstone would be a
     *                                                 tombstone against nothing
     *
     * @return array{events: list<RemoteEvent>, instances: list<RemoteInstance>}
     */
    private function eventsIn(Account $account, array $items, bool $isIncremental): array
    {
        $events    = [];
        $instances = [];
        $emitted   = [];
        $series    = [];

        foreach ($items as $item) {
            $id = trim((string) ($item['id'] ?? ''));

            if ('' === $id) {
                continue;
            }

            if (true === array_key_exists('@removed', $item)) {
                // A removed entry carries its id and nothing else — not even
                // whether it was a series or one instance of one. Emitting the
                // tombstone regardless is safe: an id the engine holds no row
                // for is asked about against the instances recorded below, and
                // does nothing when it is neither.
                if (true === $isIncremental) {
                    $events[] = RemoteEvent::deleted($id);
                }

                continue;
            }

            $type = strtolower((string) ($item['type'] ?? ''));

            if ('occurrence' === $type || 'exception' === $type) {
                $masterId = trim((string) ($item['seriesMasterId'] ?? ''));

                if ('' !== $masterId) {
                    $series[$masterId] = true;

                    $instance = $this->identityOf($item, $id, $masterId, $type);

                    if (null !== $instance) {
                        $instances[] = $instance;
                    }
                }

                // An occurrence is only a mention of its series — the rule
                // already puts it where it is, and writing it would be one row
                // per week. An exception is an instance somebody changed, and
                // that is a fact the rule does not carry: it becomes an override
                // on the series, keyed by the start the rule gave it.
                $override = 'exception' === $type
                    ? $this->instanceOverride($item, $masterId)
                    : null;

                if (null !== $override) {
                    $events[] = $override;
                }

                continue;
            }

            $remote = $this->mapper->toRemoteEvent($item);

            if (null === $remote) {
                $this->logger->warning('GraphCalendarSync: skipped an event Graph described incompletely', [
                    'remoteId' => $id,
                ]);

                continue;
            }

            $events[]                   = $remote;
            $emitted[$remote->remoteId] = true;
        }

        foreach (array_keys($series) as $masterId) {
            // Graph does return the seriesMaster in a delta window of its own
            // accord for some changes, so this is only for the series that were
            // mentioned by an instance alone.
            if (true === array_key_exists($masterId, $emitted)) {
                continue;
            }

            $master = $this->seriesMaster($account, $masterId);

            if (null === $master) {
                continue;
            }

            $events[]           = $master;
            $emitted[$masterId] = true;
        }

        return [
            'events'    => $events,
            'instances' => $instances,
        ];
    }

    /**
     * Which occurrence of which series one calendarView entry is, whether or not
     * anything about it changed.
     *
     * This is the whole answer to the cancelled instance, and it has to be
     * recorded now because there is nothing to ask later: the `@removed` entry
     * that eventually reports the deletion carries this id and no other
     * property, and the resource it names is gone, so Graph cannot be asked what
     * it was. It is written down while the occurrence still exists or it is
     * never known — see CalendarEvent::$remoteInstances, which is where the
     * engine keeps it.
     *
     * An occurrence with no `originalStart` falls back to its own start, and an
     * exception with none is left unidentified. The difference is not a
     * preference: an occurrence nothing has touched is, by definition, at the
     * start the rule gave it, so the two are the same instant. An exception's
     * start is where somebody dragged it, and recording that as its identity
     * would file a later cancellation under a day the series has no instance on.
     *
     * @param array<string,mixed> $item
     */
    private function identityOf(array $item, string $id, string $masterId, string $type): ?RemoteInstance
    {
        $originalStart = $this->mapper->originalStartOf($item)
            ?? ('occurrence' === $type ? $this->mapper->startOf($item) : null);

        if (null === $originalStart) {
            return null;
        }

        return new RemoteInstance($id, $masterId, $originalStart);
    }

    /**
     * One `type: exception` entry as an override on its series, or null when it
     * cannot be filed as one.
     *
     * `originalStart` is what makes it possible: Graph keeps the start the rule
     * gave the instance there, separately from the start somebody dragged it to,
     * and that original is the key JSCalendar files the patch under. An entry
     * without one is left as a bare mention of the series — the series is still
     * fetched and still correct, and the instance shows at its original time,
     * which is where this driver was for every exception before.
     *
     * @param array<string,mixed> $item
     */
    private function instanceOverride(array $item, string $masterId): ?RemoteEvent
    {
        if ('' === $masterId) {
            return null;
        }

        $remote = $this->mapper->toRemoteEvent($item);

        if (null === $remote) {
            return null;
        }

        $originalStart = $this->mapper->originalStartOf($item);

        if (null === $originalStart) {
            $this->logger->info('GraphCalendarSync: a changed instance carried no original start, so it stays on the series pattern', [
                'remoteId' => $remote->remoteId,
                'seriesId' => $masterId,
            ]);

            return null;
        }

        return new RemoteEvent(
            remoteId:       $remote->remoteId,
            etag:           $remote->etag,
            uid:            $remote->uid,
            isDeleted:      false,
            jscalendar:     $remote->jscalendar,
            startsAt:       $remote->startsAt,
            endsAt:         $remote->endsAt,
            seriesRemoteId: $masterId,
            recurrenceId:   $originalStart,
        );
    }

    /**
     * The recurring event an expanded instance belongs to.
     *
     * A 404 answers null rather than throwing: a series deleted between the
     * delta window being computed and this call is a series whose tombstone is
     * in the same window, and failing the whole run over it would mean the
     * tombstone never applies either.
     */
    private function seriesMaster(Account $account, string $masterId): ?RemoteEvent
    {
        $body = $this->call(
            $account,
            'GET',
            sprintf('%s/events/%s', self::ME, rawurlencode($masterId)),
            [],
            'reading a recurring event',
            [404, 410],
        );

        if ([] === $body) {
            return null;
        }

        return $this->mapper->toRemoteEvent($body);
    }

    // ── Discovery ────────────────────────────────────────────────────────────

    /**
     * The mailbox's own time zone, as the fallback for every calendar it holds.
     *
     * Graph has no per-calendar time zone at all — the property does not exist
     * on the `calendar` resource — so the choice was between leaving this null
     * and letting the provisioner use the plMail profile's zone, or asking the
     * mailbox. Asking wins, and the reason is that the two disagree exactly when
     * it matters: somebody whose Outlook is set to Europe/Berlin and whose
     * browser is elsewhere would otherwise see every synced calendar's default
     * zone differ from the one their colleagues' invitations are written in.
     * MailboxSettings.ReadWrite is already on the grant for master categories,
     * so this costs a request and no new consent.
     *
     * Read once per discovery, not once per calendar, and a failure is not one:
     * this is a nicety, and refusing to list somebody's calendars because a
     * settings read was declined would trade the feature for the default.
     */
    private function mailboxTimeZone(Account $account): ?string
    {
        try {
            $settings = $this->call(
                $account,
                'GET',
                self::ME . '/mailboxSettings/timeZone',
                [],
                'reading the mailbox time zone',
            );
        } catch (CalendarSyncException $e) {
            $this->logger->info('GraphCalendarSync: the mailbox time zone could not be read', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }

        return $this->zones->toIana(is_string($settings['value'] ?? null) ? $settings['value'] : null);
    }

    /**
     * Graph's `color` is an enum of theme names — auto, lightBlue, lightGreen —
     * and `hexColor` is the only property that holds a colour. A calendar left
     * on the default answers `auto` and an empty hexColor, and writing "auto"
     * into Calendar::$color (a `char(7)` holding #rrggbb) would produce a swatch
     * of nothing. Null instead, which is what makes CalendarProvisioner pick
     * from Calendar::COLORS.
     *
     * @param array<string,mixed> $calendar
     */
    private function hexColourOf(array $calendar): ?string
    {
        $hex = trim((string) ($calendar['hexColor'] ?? ''));

        return 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtolower($hex) : null;
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /**
     * The series' recurrenceOverrides, as writes against Graph's own occurrence
     * resources.
     *
     * A patched occurrence becomes an exception of the series, which is what a
     * recurrenceOverride already is on this side; the series' own PATCH cannot
     * say it, because `recurrence` is the pattern and an instance that differs
     * from the pattern has no home in it. Without this a per-instance edit was
     * visible in plMail alone, until a later full read carrying any exception
     * for the same series replaced the whole map and took the local patch with
     * it.
     *
     * **One instance's failure is not the push's failure.** The master has
     * already been written by the time this runs, and throwing would stop
     * CalendarPusher recording the id it just came back with — which on a create
     * means the next sweep makes a second copy of the meeting. Throttling is the
     * one thing that stops the loop instead of continuing it: the quota is gone,
     * and the requests after it would spend a retry nobody has granted.
     */
    private function pushInstances(Account $account, CalendarEvent $event, string $seriesRemoteId): void
    {
        foreach (InstanceOverride::listOf($event) as $override) {
            try {
                $this->pushInstance($account, $event, $seriesRemoteId, $override);
            } catch (CalendarSyncThrottledException $e) {
                $this->logger->warning('GraphCalendarSync: throttled while sending the instances of a series, stopping', [
                    'eventId'   => $event->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);

                return;
            } catch (CalendarSyncException $e) {
                $this->logger->warning('GraphCalendarSync: one instance of a series could not be written', [
                    'eventId'       => $event->id,
                    'originalStart' => $override->originalStart->format('Y-m-d\TH:i:s\Z'),
                    'error'         => $e->getMessage(),
                    'exception'     => $e,
                ]);
            }
        }
    }

    /**
     * One override, against whichever resource Graph currently holds for that
     * occurrence.
     *
     * The recorded id is tried first and it is not merely an optimisation. Graph
     * has no way to ask for the instance at a given original start — `/instances`
     * takes a window over where occurrences *are*, not where the rule put them —
     * so an instance already dragged a fortnight away is only addressable by the
     * id a previous pull wrote down. The window search below is the fallback,
     * and it is enough for the case it serves: an occurrence nothing has moved
     * yet is at its original start by definition.
     *
     * A 404 on the recorded id is not a failure. Graph re-keys an occurrence for
     * some edits, so an id learned last week can name nothing today, and asking
     * the series again is the recovery — where treating it as a permanent
     * refusal would abandon an instance that is sitting there under a new id.
     */
    private function pushInstance(
        Account          $account,
        CalendarEvent    $event,
        string           $seriesRemoteId,
        InstanceOverride $override,
    ): void {
        $recorded = $this->recordedInstance($event, $override);

        if (null !== $recorded) {
            try {
                $this->writeInstance($account, $recorded, $event, $override, []);

                return;
            } catch (CalendarSyncPermanentException $e) {
                if (404 !== $e->getStatus()) {
                    throw $e;
                }
            }
        }

        $instanceId = $this->instanceAt($account, $seriesRemoteId, $override);

        if (null === $instanceId) {
            // Not an error: an override whose key the rule no longer puts an
            // instance at is what a series edited after one of its occurrences
            // was moved leaves behind, and a cancellation pushed twice finds
            // nothing the second time because the first one worked.
            $this->logger->info('GraphCalendarSync: no instance of this series starts where the override says', [
                'eventId'       => $event->id,
                'originalStart' => $override->originalStart->format('Y-m-d\TH:i:s\Z'),
            ]);

            return;
        }

        // Tolerant on the second attempt, where the interface's idempotence rule
        // applies: the instance was listed a moment ago, so a 404 now is a race
        // with something else that removed it, and the outcome asked for has
        // happened either way.
        $this->writeInstance($account, $instanceId, $event, $override, [404, 410]);
    }

    /**
     * Move, rename or cancel one occurrence.
     *
     * An excluded instance is a DELETE rather than a patched `isCancelled`,
     * because that property is read-only at Graph and cancelling a meeting is
     * its own action that mails every attendee — the same restriction that makes
     * cancelling a whole event a delete here, applied to one occurrence of one.
     * Deleting an occurrence of a series does not delete the series: Graph
     * records it as a deleted occurrence, which is exactly `{"excluded": true}`.
     *
     * @param list<int> $tolerate statuses that mean the occurrence is not there
     */
    private function writeInstance(
        Account          $account,
        string           $instanceId,
        CalendarEvent    $event,
        InstanceOverride $override,
        array            $tolerate,
    ): void {
        $url = sprintf('%s/events/%s', self::ME, rawurlencode($instanceId));

        if (true === $override->isExcluded) {
            $this->call($account, 'DELETE', $url, [], 'cancelling an instance of a series', $tolerate);

            return;
        }

        $this->call(
            $account,
            'PATCH',
            $url,
            ['json' => $this->mapper->toGraphInstance($event, $override)],
            'updating an instance of a series',
            $tolerate,
        );
    }

    /**
     * The instance id a pull recorded for this override's original start.
     *
     * A scan of one series' own map rather than a query: it is already loaded,
     * it holds one entry per occurrence the provider has named, and the reverse
     * direction — id to occurrence — is the one the column is keyed for.
     */
    private function recordedInstance(CalendarEvent $event, InstanceOverride $override): ?string
    {
        $recorded = array_search(
            $override->originalStart->format(CalendarEvent::INSTANCE_START_FORMAT),
            $event->remoteInstances,
            true,
        );

        return true === is_string($recorded) ? $recorded : null;
    }

    /**
     * The occurrence of a series that begins at one original start, found by
     * asking for the instances around it.
     *
     * Graph's `/instances` demands a window and expands the series into it, so
     * the search is a day either side and the match is made here on
     * `originalStart` — the property that survives a drag. A day is enough
     * because of what this fallback is for: an occurrence that has never been
     * changed, which is at its original start, and one that has been is
     * addressable by the id the pull that carried it recorded. Widening the
     * window would not help the case it cannot serve — an exception moved
     * further than the window, whose id was never seen — and would fetch a
     * fortnight of occurrences to look at one.
     */
    private function instanceAt(Account $account, string $seriesRemoteId, InstanceOverride $override): ?string
    {
        $page = $this->collect(
            $account,
            sprintf('%s/events/%s/instances', self::ME, rawurlencode($seriesRemoteId)),
            [
                'startDateTime' => $override->originalStart->modify('-1 day')->format('Y-m-d\TH:i:s\Z'),
                'endDateTime'   => $override->originalStart->modify('+1 day')->format('Y-m-d\TH:i:s\Z'),
            ],
            'looking for one instance of a series',
        );

        foreach ($page['items'] as $item) {
            $id    = trim((string) ($item['id'] ?? ''));
            $start = $this->mapper->originalStartOf($item) ?? $this->mapper->startOf($item);

            if ('' === $id || null === $start) {
                continue;
            }

            if ($start->getTimestamp() === $override->originalStart->getTimestamp()) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $body the event resource Graph answered with
     */
    private function writeResult(array $body, ?string $knownId): RemoteWriteResult
    {
        $id = trim((string) ($body['id'] ?? ''));

        if ('' === $id) {
            $id = (string) $knownId;
        }

        if ('' === $id) {
            // A create whose response carried no id leaves an event at the
            // remote that nothing here can ever find again, which is worse than
            // a failed push: the retry makes a second one.
            throw new CalendarSyncException('Microsoft Graph accepted the event but did not say what its id is.');
        }

        $etag = trim((string) ($body['@odata.etag'] ?? ''));

        return new RemoteWriteResult($id, '' === $etag ? null : $etag);
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Follow @odata.nextLink to the end of the window, keeping the deltaLink.
     *
     * The paging loop is here rather than in the engine because the engine must
     * not learn the difference between the two links — see CalendarChangeSet.
     * The token returned is the `@odata.deltaLink` from the final page and never
     * a `nextLink`: storing an intermediate cursor as the sync token would make
     * the next run resume in the middle of a window it had already applied and
     * then treat that partial page as the whole of it.
     *
     * @param array<string,string> $query
     *
     * @return array{items: list<array<string,mixed>>, deltaLink: string|null}
     */
    private function collect(Account $account, string $url, array $query, string $what): array
    {
        $items     = [];
        $deltaLink = null;
        $next      = $url;
        $isFirst   = true;

        while (null !== $next) {
            $options = [];

            if (true === $isFirst && [] !== $query) {
                $options['query'] = $query;
            }

            $body    = $this->call($account, 'GET', $next, $options, $what);
            $isFirst = false;

            $value = $body['value'] ?? null;

            foreach (true === is_array($value) ? $value : [] as $item) {
                if (true === is_array($item)) {
                    $items[] = $item;
                }
            }

            $link = $body['@odata.nextLink'] ?? null;
            $next = is_string($link) && '' !== $link ? $link : null;

            $delta = $body['@odata.deltaLink'] ?? null;

            if (true === is_string($delta) && '' !== $delta) {
                $deltaLink = $delta;
            }
        }

        return [
            'items'     => $items,
            'deltaLink' => $deltaLink,
        ];
    }

    /**
     * Every outbound call, with the bearer token applied and every failure
     * already translated.
     *
     * @param array<string,mixed> $options
     * @param list<int>           $tolerate statuses that answer an empty body
     *                                      instead of throwing — idempotent
     *                                      deletes and optional reads
     *
     * @return array<string,mixed>
     */
    private function call(
        Account $account,
        string  $method,
        string  $url,
        array   $options,
        string  $what,
        array   $tolerate = [],
    ): array {
        $options['auth_bearer'] = $this->accessToken($account);

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new CalendarSyncException(
                sprintf('Microsoft Graph could not be reached while %s.', $what),
                0,
                $e,
            );
        }

        if (true === in_array($status, $tolerate, true)) {
            return [];
        }

        if (200 > $status || 300 <= $status) {
            throw $this->translate($response, $status, $what);
        }

        // Graph answers a successful DELETE with 204 and no body at all, and
        // toArray() on that is a decoding error rather than an empty array.
        if (204 === $status) {
            return [];
        }

        try {
            return $response->toArray(false);
        } catch (DecodingExceptionInterface | TransportExceptionInterface | \JsonException $e) {
            throw new CalendarSyncException(
                sprintf('Microsoft Graph answered something that is not JSON while %s.', $what),
                $status,
                $e,
            );
        }
    }

    /**
     * A refresh that fails is a grant that is gone.
     *
     * Permanent rather than unclassified: OAuthTokenManager has already asked
     * Microsoft for a new token and been refused, and asking again with the same
     * dead refresh token is the definition of a retry that cannot work. The
     * wording is the one a person can act on, because this is what the settings
     * page will show them.
     */
    private function accessToken(Account $account): string
    {
        try {
            return $this->tokens->getValidAccessToken($account);
        } catch (OAuthGrantRevokedException $e) {
            throw new CalendarSyncPermanentException(
                'Microsoft would not renew the sign-in for this account. Reconnect it in the account settings.',
                401,
                $e,
            );
        } catch (\Throwable $e) {
            // Not every failed refresh is a dead grant. This used to write the
            // account off for a timeout against Entra's token endpoint, which
            // is a retry that WOULD have worked — the permanent branch above is
            // now taken only when the provider has said the grant is finished.
            throw new CalendarSyncException(
                'Microsoft could not renew the sign-in for this account.',
                401,
                $e,
            );
        }
    }

    /**
     * Which failure this is, in the vocabulary the engine understands.
     *
     * The message is assembled from Graph's own error code and text rather than
     * the raw body, and never carries the URL: the request line holds the
     * calendar id and, on a resumed delta call, the whole sync token, and this
     * string is displayed to the user.
     */
    private function translate(ResponseInterface $response, int $status, string $what): CalendarSyncException
    {
        $error   = $this->errorOf($response);
        $code    = $error['code'];
        $message = $error['message'];

        if (410 === $status) {
            return new CalendarResyncRequiredException(sprintf(
                'Microsoft Graph can no longer continue from the stored sync position (%s), so the calendar is being read again from scratch.',
                '' === $code ? 'resyncRequired' : $code,
            ));
        }

        if (412 === $status) {
            return new CalendarResyncRequiredException(
                'The event changed at Microsoft since plMail last read it, so the update was not applied.',
                412,
            );
        }

        if (429 === $status || 503 === $status) {
            return new CalendarSyncThrottledException(
                sprintf('Microsoft Graph is rate-limiting this account while %s.', $what),
                $status,
                $this->retryAfter($response),
            );
        }

        if (401 === $status || (403 === $status && true === $this->isAuthorizationFailure($code, $message))) {
            return new CalendarSyncPermanentException(self::RECONNECT_HINT, $status);
        }

        if (400 === $status || 404 === $status) {
            return new CalendarSyncPermanentException(
                sprintf('Microsoft Graph refused this request while %s: %s', $what, $this->describe($code, $message)),
                $status,
            );
        }

        return new CalendarSyncException(
            sprintf('Microsoft Graph failed while %s (%d): %s', $what, $status, $this->describe($code, $message)),
            $status,
        );
    }

    /**
     * Whether a 403 is about permission rather than about something that clears.
     *
     * Both the code and the text are consulted because Graph does not always
     * send a code, and "Access is denied" with an empty code is the shape a
     * mailbox without the calendar scope answers with.
     */
    private function isAuthorizationFailure(string $code, string $message): bool
    {
        if (true === in_array(strtolower($code), self::AUTHORIZATION_CODES, true)) {
            return true;
        }

        $text = strtolower($message);

        return true === str_contains($text, 'access is denied')
            || true === str_contains($text, 'scope');
    }

    /**
     * Seconds, or null when Graph sent no Retry-After or sent an HTTP date.
     *
     * The date form is legal and is deliberately not parsed: it is answered by
     * proxies rather than by Graph, and misreading "Wed, 21 Oct 2026 07:28:00
     * GMT" as an integer yields 3, which is a retry inside the window that
     * refused us. Null falls back to
     * CalendarSyncThrottledException's own minute.
     */
    private function retryAfter(ResponseInterface $response): ?int
    {
        try {
            $header = $response->getHeaders(false)['retry-after'][0] ?? null;
        } catch (TransportExceptionInterface) {
            return null;
        }

        return true === ctype_digit((string) $header) ? (int) $header : null;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function errorOf(ResponseInterface $response): array
    {
        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            return ['code' => '', 'message' => ''];
        }

        $decoded = json_decode($body, true);
        $error   = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (false === is_array($error)) {
            // A proxy in front of Graph answers HTML, not JSON. The first line
            // of it is more use than nothing and less use than the whole page.
            return ['code' => '', 'message' => mb_substr(trim($body), 0, 200)];
        }

        return [
            'code'    => trim((string) ($error['code'] ?? '')),
            'message' => mb_substr(trim((string) ($error['message'] ?? '')), 0, 200),
        ];
    }

    private function describe(string $code, string $message): string
    {
        if ('' === $code) {
            return '' === $message ? 'no reason given' : $message;
        }

        return '' === $message ? $code : sprintf('%s: %s', $code, $message);
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    /**
     * The registry only ever hands this driver a source it claimed, so a source
     * with no account is a wiring bug rather than a remote failure. It still has
     * to be an exception of the right family: a TypeError here would escape the
     * engine's own error recording and leave the calendar with no lastSyncError
     * at all.
     */
    private function accountOf(CalendarSource $source): Account
    {
        $account = $source->account;

        if (null === $account) {
            throw new CalendarSyncPermanentException('This calendar source has no Microsoft account behind it.');
        }

        return $account;
    }

    private function accountOfCalendar(Calendar $calendar): Account
    {
        $account = $calendar->account;

        if (null === $account) {
            throw new CalendarSyncPermanentException(sprintf(
                'Calendar #%d is not attached to a Microsoft account.',
                (int) $calendar->id,
            ));
        }

        return $account;
    }

    private function remoteIdOf(Calendar $calendar): string
    {
        $remoteId = trim((string) $calendar->remoteId);

        if ('' === $remoteId) {
            throw new CalendarSyncPermanentException(sprintf(
                'Calendar #%d has no Microsoft calendar behind it to sync with.',
                (int) $calendar->id,
            ));
        }

        return $remoteId;
    }
}
