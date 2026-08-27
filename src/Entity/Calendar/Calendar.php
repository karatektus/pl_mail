<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One list of events, owned by a user.
 *
 * User-scoped like Label and MailRule, and for the same reason: a calendar is
 * the user's, not an account's. The optional account is what makes "the calendar
 * the email came from" expressible — every mail account is provisioned a
 * CalendarRole::Account calendar. Extraction does not use it by default;
 * ExtractedEventCalendarResolver files into the user's default calendar, and
 * this is where it files instead when Account::SETTING_CALENDAR_TARGET names
 * one.
 *
 * The optional integration is for calendars mirrored from elsewhere. It is
 * declared now rather than added later because remote_id and sync_token are
 * meaningless without knowing which remote they belong to, and a partial unique
 * index over the three is what stops a re-sync duplicating every calendar.
 */
#[ORM\Entity(repositoryClass: CalendarRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calendar')]
#[ORM\Index(name: 'idx_calendar_usr', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_calendar_account', columns: ['account_id'])]
// One row per live push channel, and the index the webhooks read by. Unique
// rather than plain, because both webhooks resolve a notification to exactly
// one calendar by this column alone — two rows carrying the same channel id
// would make a notification ambiguous, and the endpoint would either sync the
// wrong calendar or sync both on somebody else's secret. Postgres allows any
// number of NULLs in a unique index, which is what keeps this usable on the
// column's ordinary state: a calendar with no channel.
#[ORM\UniqueConstraint(name: 'uniq_calendar_push_channel_id', columns: ['push_channel_id'])]
// One local calendar per remote calendar, per source — the partial unique index
// the class docblock above promised and nothing had. Two rows mirroring one
// remote calendar are two mirrors of one thing: the sweep pulls its events
// twice, and the editor offers both as destinations, so ticking them sends one
// meeting to one provider calendar TWICE as two events with two ids that
// nothing here can ever merge again. CalendarDiscoverer already declines to
// subscribe a remote it is mirroring; this is what makes that a fact rather than
// a convention a race or a restored backup can walk past.
//
// Keyed on the SOURCE and the id, never on the user and the id.
// IcsUrlCalendarDriver::REMOTE_ID is the constant `feed` for every subscribed
// address — a feed is one calendar, so it has nothing to distinguish — and a
// user with two of those is ordinary. What tells them apart is the Integration
// each hangs off. NULLs are distinct in a PostgreSQL unique index, which is what
// leaves the ordinary state of both columns unconstrained; see the note above.
#[ORM\UniqueConstraint(name: 'uniq_calendar_account_remote', columns: ['account_id', 'remote_id'])]
#[ORM\UniqueConstraint(name: 'uniq_calendar_integration_remote', columns: ['integration_id', 'remote_id'])]
class Calendar
{
    use TimestampableTrait;

    /** Fallback when a user has expressed no preference. */
    public const string DEFAULT_COLOR = '#2563eb';

    /**
     * The colours a calendar may be, and the order they are offered in.
     *
     * Here rather than on CalendarProvisioner, which is where they started:
     * the provisioner walks the list so a second account is not the same blue
     * as the first, and the settings picker offers it so a user's own calendar
     * can be any colour a provisioned one can. Two lists would drift, and the
     * drift is invisible until somebody makes a calendar in a colour the
     * palette no longer contains.
     *
     * @var list<string>
     */
    public const array COLORS = [
        '#2563eb', '#7c3aed', '#db2777', '#ea580c',
        '#16a34a', '#0891b2', '#ca8a04', '#dc2626',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $usr = null;

    /**
     * The mail account this calendar belongs to, for CalendarRole::Account.
     * Null on every other role.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Account $account = null;

    /** The connection this calendar mirrors, for CalendarRole::Remote. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Integration $integration = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    /** #rrggbb, matching Label's colour handling. */
    #[ORM\Column(length: 7, options: ['default' => self::DEFAULT_COLOR])]
    public string $color = self::DEFAULT_COLOR;

    /**
     * IANA zone this calendar is displayed in. Events carry their own; this is
     * the fallback for ones that do not, and the zone a new event starts in.
     */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    public string $timeZone = 'UTC';

    #[ORM\Column(length: 20, enumType: CalendarRole::class, options: ['default' => 'custom'])]
    public CalendarRole $role = CalendarRole::Custom;

    /** Unticked in the sidebar hides it from every view without deleting it. */
    #[ORM\Column(options: ['default' => true])]
    public bool $isVisible = true;

    /** The one a new event lands in when nothing else picked. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isDefault = false;

    /** A mirror of somewhere that does not accept writes back. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isReadOnly = false;

    #[ORM\Column(options: ['default' => 0])]
    public int $sortOrder = 0;

    /** Opaque id at the remote, for CalendarRole::Remote. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $remoteId = null;

    /** CalDAV sync-token or provider equivalent; opaque, never parsed. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $syncToken = null;

    /**
     * When a sync last completed without throwing. Null on a calendar that has
     * never synced, which includes every calendar that mirrors nothing.
     *
     * This is what the sweep orders by, so it is also the answer to "which
     * calendar has been waiting longest" — and it is written on a run that
     * found nothing to do, because a poll that discovered no changes is still a
     * poll that succeeded.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastSyncedAt = null;

    /**
     * Why the last sync failed, or null if it succeeded. Mirrors
     * Integration::$lastError, for the same reason and with the same rule: it
     * is rendered in the calendar settings list, so it is phrased for a person
     * and never carries a credential.
     *
     * Cleared on the next success rather than accumulating a history. A
     * calendar that failed once and has worked since is a calendar with nothing
     * wrong with it, and a stale error beside a working calendar teaches people
     * to ignore the field.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $lastSyncError = null;

    /**
     * How many times in a row this calendar's sync has failed. Reset to zero by
     * the first success.
     *
     * Only ever read to work out how long to wait before the next attempt —
     * it is not a statistic and nothing renders it.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $syncFailureCount = 0;

    /**
     * Do not attempt this calendar again before this moment. Null when it is
     * healthy, or when a repair has just asked for an immediate retry.
     *
     * ── Why this column exists ───────────────────────────────────────────────
     * The sweep asks findDueForSync() for calendars whose lastSyncedAt is old,
     * and a calendar that CANNOT sync never updates lastSyncedAt — so it is due
     * again fifteen minutes later, and again, permanently. On the install this
     * was built from, one expired Google sign-in produced 2 193 identical error
     * lines and 503 dead-lettered jobs from three calendars over two days.
     *
     * That is a defect on its own terms and not only a tidiness problem: the
     * one log line that says what is wrong is worth nothing when it is repeated
     * every quarter of an hour, because the volume is what makes people stop
     * reading the log. Retrying something at the same rate forever is also not
     * a retry policy — it is the absence of one.
     *
     * Note what this is deliberately NOT: a reclassification of the error.
     * GoogleCalendarApiClient::token() refuses to call a refused refresh
     * permanent, because a slow token endpoint arrives as the same object, and
     * that judgement is correct. Backing off is the answer that does not
     * require telling the two apart — it slows down without ever giving up.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $syncBackoffUntil = null;

    /**
     * Whether the failure just recorded was news, as opposed to the same thing
     * this calendar is already backing off through.
     *
     * NOT a column, and deliberately so — it is true of one attempt, not of the
     * calendar, and persisting it would invite somebody to read it a request
     * later when it means nothing. It exists because recordSyncFailure() is
     * called from a catch block that must rethrow, so its answer cannot be
     * returned up the stack to SyncCalendarHandler, which is what needs it.
     *
     * Lives on the entity rather than on the sync service because the service
     * is a singleton the FrankenPHP worker keeps alive across requests: state
     * parked there would be read back by whichever calendar came next.
     */
    public bool $syncFailureWasNews = true;

    /**
     * Free-form per-calendar preferences, mirroring Account::$settings — the
     * long tail that does not deserve a column. Readers assume their defaults
     * at the call site.
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $settings = [];

    /**
     * The registration that makes changes at the remote arrive instead of being
     * waited for — a Google `events.watch` channel id, or a Graph subscription
     * id. Null on every calendar that is polled, which is all of them until a
     * deployment has a public HTTPS address.
     *
     * Four columns rather than a JSON blob in $settings, and that is the one
     * decision here worth arguing. This is what the two unauthenticated,
     * internet-facing webhooks look a notification up by and verify it against;
     * it needs a unique index (see the constraint above) and a constant-time
     * comparison against a known column, and neither is something to build on a
     * key inside a jsonb document whose readers assume their own defaults.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $pushChannelId = null;

    /**
     * Google's `resourceId` for the channel, and the reason it is stored: a
     * channel is stopped by POSTing the pair (id, resourceId) to channels/stop,
     * and the resourceId is only ever seen in the answer to the watch call. Not
     * keeping it means never being able to stop the channel — it goes on
     * delivering to an endpoint that no longer knows it, for the whole week it
     * was registered for.
     *
     * Null for Microsoft, which cancels by subscription id alone.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $pushResourceId = null;

    /**
     * The secret the provider echoes back on every notification — Google's
     * channel `token`, Graph's `clientState` — and the only thing that makes
     * these endpoints anything other than a free remote trigger. 256 bits of
     * CSPRNG, minted per registration, compared with hash_equals.
     *
     * Not encrypted, matching Account::$graphSubscriptionClientState: it is
     * minted here, means nothing anywhere else, and dies with the registration.
     */
    #[ORM\Column(length: 128, nullable: true)]
    public ?string $pushSecret = null;

    /**
     * When the registration lapses, as the provider stated it — not as plMail
     * asked for it. Google answers a watch with the expiration it actually
     * granted, which need not be the ttl requested, and Graph does the same
     * with expirationDateTime. Renewing off a local constant instead is how a
     * channel silently stops a day before anything tries to renew it.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $pushExpiresAt = null;

    /** @var Collection<int, CalendarEvent> */
    #[ORM\OneToMany(targetEntity: CalendarEvent::class, mappedBy: 'calendar')]
    public private(set) Collection $events;

    public function __construct()
    {
        $this->events = new ArrayCollection();
    }

    /**
     * Whether there is a remote behind this at all.
     *
     * Stays a method: it asks a role and a nullable column one question
     * together, which is an interpretation of two pieces of state rather than
     * the plain read $isVisible and $isReadOnly are. Both halves are needed —
     * the role alone is true for a Remote calendar the subscribe flow created
     * before it learned the id, and a remoteId alone would one day be true of
     * something that is not a mirror.
     */
    public function isSynced(): bool
    {
        return CalendarRole::Remote === $this->role && null !== $this->remoteId;
    }

    /** One key out of the settings bag, or the caller's default. */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Mirrors Account::setSetting() and Integration::setSetting(), down to the
     * reason: reassign rather than mutate in place, because Doctrine compares a
     * JSON column by value against what it hydrated, and writing into the same
     * array instance leaves both sides of that comparison pointing at the same
     * data — the change is made and never persisted.
     *
     * The bag has been here since the first pass and was read with `??` at each
     * call site, which worked only because nothing had written to it yet.
     */
    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings;
        $settings[$key] = $value;

        $this->settings = $settings;
    }

    /**
     * Mirrors Integration::recordSuccess() deliberately, down to the name: a
     * connection that has quietly stopped working should say so in the same
     * way wherever it is listed, and two spellings of "it is fine now" is how
     * one of them ends up not clearing the error.
     */
    public function recordSyncSuccess(): void
    {
        $this->lastSyncedAt  = new DateTimeImmutable();
        $this->lastSyncError = null;

        // One success is enough to earn the full rate back. Decaying the count
        // instead would leave a calendar that recovered still being polled
        // hourly, with nothing on screen to explain why it looks stale.
        $this->syncFailureCount = 0;
        $this->syncBackoffUntil = null;
    }

    /**
     * Record a failure and decide how long to wait, answering whether this one
     * is worth a log line.
     *
     * The answer is true when the failure is NEWS — the first of a run, a
     * different message from the one already stored, or the first attempt after
     * a backoff window has expired. It is false only while the calendar is
     * inside a window it is already backing off through, which is exactly the
     * repetition that produced two thousand identical lines.
     *
     * The first occurrence is therefore never suppressed: on a healthy calendar
     * syncBackoffUntil is null, so the first failure always reports. Nor can a
     * changed condition hide behind an old one — a different message reports
     * immediately, whatever the window says.
     *
     * Truncated like Integration::recordFailure(): a provider stack trace is
     * not a message.
     */
    public function recordSyncFailure(string $reason, ?DateTimeImmutable $now = null): bool
    {
        $now       = $now ?? new DateTimeImmutable();
        $truncated = mb_substr($reason, 0, 500);

        $isNews = null === $this->syncBackoffUntil
            || $this->syncBackoffUntil <= $now
            || $this->lastSyncError !== $truncated;

        $this->lastSyncError        = $truncated;
        $this->syncFailureWasNews   = $isNews;

        // Counted per reported failure rather than per attempt. Messenger
        // retries the same envelope several times, and letting each attempt
        // advance the schedule would take a calendar from "wait a quarter of an
        // hour" to "wait a day" on a single bad afternoon.
        if (true === $isNews) {
            ++$this->syncFailureCount;
            $this->syncBackoffUntil = $now->modify(sprintf('+%d seconds', $this->backoffSeconds()));
        }

        return $isNews;
    }

    /**
     * Try again now, whatever the schedule said.
     *
     * The repair button's half of the bargain. A calendar that has backed off
     * is not due, so dispatching a sync without this would be answered by a
     * handler that declines to run — a button that visibly does nothing.
     *
     * lastSyncError is deliberately left alone: it is still true until a sync
     * succeeds, and clearing it here would blank the health page the instant
     * the button was pressed, whether or not anything got better.
     */
    public function clearSyncBackoff(): void
    {
        $this->syncFailureCount = 0;
        $this->syncBackoffUntil = null;
    }

    /**
     * A retry has been asked for by hand and has not reported back yet.
     *
     * Derived rather than stored, and the derivation is exact rather than a
     * heuristic. Three columns and only three states can produce this shape:
     *
     *   - lastSyncError is set, so the last thing this calendar DID was fail;
     *   - syncFailureCount is zero, and only two things zero it —
     *     recordSyncSuccess(), which also nulls lastSyncError and is therefore
     *     excluded by the first clause, and clearSyncBackoff(), which is called
     *     from exactly one place: the repair button;
     *   - syncBackoffUntil is null, which recordSyncFailure() re-arms on the
     *     first failure of any run (a healthy calendar has a null window, so
     *     the first failure is always news and always counts).
     *
     * So this is true between "the user pressed Try syncing now" and "the
     * worker reported an outcome", and false at every other moment. That is
     * what lets the health card say the honest thing after the redirect — the
     * sync was STARTED, not finished — and go on saying it across a reload,
     * rather than showing an enabled button that invites a second press for
     * work already queued.
     *
     * Deliberately not a timeout: a stale answer here is a card that says it is
     * waiting when nothing is coming, which the surface handles by offering the
     * button back rather than by this method guessing at a clock.
     */
    public function isAwaitingRequestedSync(): bool
    {
        return null !== $this->lastSyncError
            && 0 === $this->syncFailureCount
            && null === $this->syncBackoffUntil;
    }

    /** Whether this calendar is inside a window it agreed to wait out. */
    public function isBackingOff(?DateTimeImmutable $now = null): bool
    {
        if (null === $this->syncBackoffUntil) {
            return false;
        }

        return $this->syncBackoffUntil > ($now ?? new DateTimeImmutable());
    }

    /**
     * How long to wait after the nth consecutive failure.
     *
     * Doubling from a quarter of an hour, capped at a day. The cap is the part
     * that matters: without it a calendar that failed for a fortnight would
     * next be tried a fortnight later, so a grant the user repaired this
     * morning would sit dark until some time next month. A day means the worst
     * case for a condition that silently fixed itself is one day, while a
     * permanently dead calendar costs one attempt and one log line per day
     * instead of ninety-six.
     */
    private function backoffSeconds(): int
    {
        $steps = [900, 1800, 3600, 7200, 14400, 28800, 86400];
        $index = max(0, $this->syncFailureCount - 1);

        return $steps[min($index, count($steps) - 1)];
    }

    /**
     * Whether a notification claiming this calendar could be genuine.
     *
     * Stays a method: it asks two columns one question, the way isSynced()
     * does. Both halves are needed and neither is enough — a channel id with no
     * secret cannot be verified, so a notification carrying it must be refused
     * rather than trusted, and that is exactly the state a half-written
     * registration leaves behind.
     */
    public function hasPushChannel(): bool
    {
        return null !== $this->pushChannelId && null !== $this->pushSecret;
    }

    /**
     * Forget the registration, all four columns at once.
     *
     * A named method rather than four assignments at each call site, for the
     * reason mutators exist here at all: the columns are only meaningful
     * together. A teardown that cleared the id and left the secret would leave
     * a calendar that verifies notifications for a channel it can no longer
     * stop, and the next registration would be the second live channel on the
     * same calendar rather than a replacement for the first.
     */
    public function clearPushChannel(): void
    {
        $this->pushChannelId  = null;
        $this->pushResourceId = null;
        $this->pushSecret     = null;
        $this->pushExpiresAt  = null;
    }
}
