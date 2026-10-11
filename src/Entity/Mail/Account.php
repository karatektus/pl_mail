<?php

namespace App\Entity\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Model\AccountModel;
use App\Entity\User\User;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\Mail\AccountRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Account extends AccountModel
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $name = null;

    /**
     * Where the account sits in the user's own arrangement of the list.
     *
     * DISPLAY ONLY. It used to decide two other things as well — which account
     * was primary and which colour the account wore — so tidying the list by
     * dragging a row silently reassigned the address Compose sent from, and
     * repainted every account dot in the app. Both now live in fields of their
     * own; this one moves rows and nothing else.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $sortOrder = 0;

    /**
     * The account Compose starts from. Exactly one per user, chosen explicitly.
     *
     * Derived from sortOrder === 0 until it was found that a drag rewrote it,
     * which is not something a person tidying a list is asking for and which
     * the UI never mentioned. AccountCreator::ensurePrimary() keeps the "exactly
     * one" part true across creation and deletion.
     */
    #[ORM\Column]
    public bool $isPrimary = false;

    /**
     * Which entry of the account palette this account paints its dot with.
     *
     * Assigned once, at creation, and then left alone — that is the whole point
     * of it. The dot was keyed off sortOrder, so a reorder swapped the colours
     * of two accounts: the mark whose only job is "this is the same account you
     * saw on that message" changed meaning under the user, on the sidebar and
     * on every list row at once. Dense and lowest-free at creation, so the first
     * eight accounts are still guaranteed distinct, which is why sortOrder was
     * used in the first place.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $colorIndex = 0;

    #[ORM\ManyToOne(inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $usr = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $imapHost = null;

    #[ORM\Column(nullable: true)]
    public ?int $imapPort = null;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $imapEncryption = null;

    #[ORM\Column(length: 255)]
    public ?string $username = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $password = null;

    /**
     * A password for sending, where the server wants a different one than for
     * reading. Null — which is nearly always — means there is one password and
     * it is $password.
     *
     * Zoho hands out an app password per protocol, and some hosts front their
     * SMTP with a relay that has credentials of its own. Until this existed
     * such an account could be added and would sync, and every send failed on
     * authentication with nothing in the form to put the second password in
     * (#42).
     *
     * Only the password differs. The username is shared: nobody has asked for
     * two, and the form stays one field shorter for everyone who has one.
     * Read through $sendingPassword, never directly, by anything that sends.
     */
    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $smtpPassword = null;

    /**
     * What SMTP authenticates with: the sending password if the account has
     * one, the account's password otherwise.
     *
     * Virtual, and the one place the fallback is written, so a sender cannot
     * read $password by habit and work for every account but the ones this
     * was added for. An empty string counts as "has none": that is what a
     * form field left blank submits.
     */
    public ?string $sendingPassword {
        get => null !== $this->smtpPassword && '' !== $this->smtpPassword
            ? $this->smtpPassword
            : $this->password;
    }

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $smtpHost = null;

    #[ORM\Column(nullable: true)]
    public ?int $smtpPort = null;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $smtpEncryption = null;

    #[ORM\Column(length: 20)]
    public ?string $authType = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $oauthProvider = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $oauthAccessToken = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $oauthRefreshToken = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $oauthTokenExpiry = null;

    /**
     * The scopes the provider actually GRANTED, as it spelled them.
     *
     * Not the scopes we asked for — those are a constant and live on
     * MailProvider. This is the answer, and it is routinely narrower than the
     * question: Google lets a user decline calendar access on the consent
     * screen and still hands back a working token, and a Microsoft tenant can
     * be configured to withhold the same permission.
     *
     * Stored because the difference is otherwise learned days later, as
     * calendars that "stopped syncing" with a 403 — see
     * MailProvider::grantsCalendarAccess() and the health card built from it.
     *
     * Null on an account connected before this was recorded, and on one that
     * does not use OAuth at all. Null means "not known", never "nothing
     * granted": nothing may be reported as missing on the strength of it.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $oauthGrantedScopes = null;

    /**
     * Why the provider last refused a change we tried to push, permanently.
     *
     * Set when an export is turned away for a reason that will not change on
     * its own — insufficient scopes, above all. Cleared by the next export that
     * works, so it describes the present rather than a bad afternoon.
     *
     * It exists because the alternative is silence. A refused export leaves the
     * change applied HERE and nowhere else: marking five thousand conversations
     * read succeeds on screen, never reaches Gmail, and is undone by the next
     * sync — with nothing but a log line to say why. This is what the health
     * page reads to say it out loud.
     *
     * Null on an account that has never had one refused, which is almost all of
     * them.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $exportRefusedReason = null;

    #[ORM\Column]
    public ?bool $isActive = null;

    /**
     * When this account last synced without error.
     *
     * Present since the first migration and, until now, written by nothing and
     * read by nothing — a column that looked like it meant something and did
     * not. SyncAccountMessageHandler sets it now, which is what gives
     * lastSyncError below something to be measured against.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastSyncedAt = null;

    /**
     * Why this account's last sync failed, or null when it did not.
     *
     * A mail account recorded NOTHING about failing. Calendar has had a full
     * account of it for as long as it has synced — a failure count, a backoff
     * ladder, a message, and a rule for which failures are worth a log line —
     * and the mailbox, which is the thing the application is for, had a
     * timestamp nobody wrote. An IMAP server that had been refusing
     * connections for a week was indistinguishable from one that synced a
     * minute ago.
     *
     * Truncated like Calendar::recordSyncFailure() and Integration::recordFailure():
     * a provider stack trace is not a message.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lastSyncError = null;

    /**
     * Consecutive failed syncs, reset by the first success.
     *
     * The health page waits for this to reach a threshold before saying
     * anything, which is the whole reason it is counted rather than merely
     * flagged. A dropped connection at three in the morning is not news; the
     * same mailbox failing every attempt for a day is. Reporting the first
     * failure would put a card on the page for every transient blip and teach
     * people to ignore the page.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $syncFailureCount = 0;

    /**
     * The last `[ALERT]` the IMAP server sent, verbatim.
     *
     * RFC 3501 §7.1 is not ambiguous about this: "The human-readable text
     * contains a special alert that MUST be presented to the user in a fashion
     * that calls the user's attention to the message." plMail read those lines
     * off the socket and dropped them, which made it a MUST-violating client.
     *
     * What actually arrives this way is the mail nobody else will tell you
     * about: `Quota exceeded (mailbox for user is full)`, `Your password
     * expires in 3 days`, an app-password deprecation notice, a server about to
     * be migrated. The quota one matters most here — plMail's own preset list
     * is dominated by German consumer ISPs with small free tiers, and an
     * over-quota mailbox is one where new mail simply stops arriving, with
     * nothing on screen to say why.
     *
     * Cleared by the next connection that carries none, on the same contract as
     * $exportRefusedReason: it describes the present, not a bad afternoon.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $imapServerAlert = null;

    /**
     * How many times this account has had to re-enumerate its whole mailbox.
     *
     * Both providers hand out a sync cursor that can expire — Gmail's historyId
     * after about thirty days, Graph's delta link on its own schedule — and the
     * answer to either is to list everything again and work out what is missing.
     * That recovery is correct and normal, occasionally.
     *
     * It is not normal often. A cursor that keeps expiring means the account is
     * not being synced inside the window the provider keeps, or a worker is
     * dying mid-batch and never committing the new one — and the symptom of
     * that is a mailbox that is intermittently behind, which looks like nothing
     * in particular from the outside.
     *
     * Recorded rather than surfaced on its own: one re-sync is housekeeping and
     * a card about it would be noise. It rides along as evidence on the card
     * that fires when syncing is actually failing — see HealthFact, which
     * exists so somebody can check the reasoning rather than take a verdict on
     * faith.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $fullResyncCount = 0;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastFullResyncAt = null;

    /** A cursor expired and the whole mailbox had to be listed again. */
    public function recordFullResync(): void
    {
        ++$this->fullResyncCount;
        $this->lastFullResyncAt = new DateTimeImmutable();
    }


    #[ORM\Column(length: 255, nullable: true)]
    public ?string $gmailHistoryId = null;

    /**
     * When the users.watch() registration for this mailbox expires.
     * Google watch registrations last at most 7 days and must be renewed.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $gmailWatchExpiry = null;

    /**
     * The resource name returned by users.watch() — stored so we can call
     * users.stop() if the account is disconnected.
     */
    #[ORM\Column(length: 512, nullable: true)]
    public ?string $gmailWatchResourceName = null;

    /**
     * @var Collection<int, EmailAlias>
     */
    #[ORM\OneToMany(targetEntity: EmailAlias::class, mappedBy: 'account', cascade: ['persist'], orphanRemoval: true)]
    public private(set) Collection $aliases;

    /**
     * @var Collection<int, Mailbox>
     */
    #[ORM\OneToMany(targetEntity: Mailbox::class, mappedBy: 'account')]
    public private(set) Collection $mailboxes;

    /**
     * @var Collection<int, MessageThread>
     */
    #[ORM\OneToMany(targetEntity: MessageThread::class, mappedBy: 'account')]
    public private(set) Collection $messageThreads;

    /**
     * Not every message hangs off a mailbox or a thread (drafts and
     * partially-synced rows can have both null), so the account owns them
     * directly too — otherwise deleting it trips message.account_id.
     *
     * Deleting an account cascades in the database, not in the ORM: a mailbox
     * can hold six figures of messages and hydrating them all just to issue
     * one DELETE each would exhaust memory. See the join columns on Message.
     *
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'account')]
    public private(set) Collection $messages;

    /**
     * Free-form per-account settings. Empty by default; readers assume their
     * defaults at the call site via getSetting($key, $default).
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $settings = [];

    /**
     * Last time Google's Pub/Sub push actually reached /gmail/push for this
     * account — distinguishes "watch registered but subscription broken"
     * from "healthy but quiet".
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $gmailLastPushAt = null;

    /**
     * Last time this mailbox was found to have CHANGED in a way push announces:
     * the moment the history advanced with a change to mail in the inbox,
     * whoever noticed it.
     *
     * The counterpart gmailLastPushAt needed to mean anything. Elapsed silence
     * on its own cannot tell a broken push from a quiet mailbox, and every
     * threshold that tries is a guess that either cries wolf at people who get
     * little mail or stays quiet for a day and a half at people whose push has
     * died. This column removes the guess: an inbox change recorded well after
     * the last push is a change that push failed to announce — evidence, not
     * an inference. Changes elsewhere in the mailbox (sent mail, archived and
     * filtered mail, spam) are never pushed and so never recorded here; see
     * GmailApiSyncer::announcedByPush().
     *
     * A genuinely quiet mailbox never advances its history, so it never
     * produces this evidence and never raises anything, at any hour. That is
     * the false-alarm case handled by construction rather than by a constant.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $gmailHistoryAdvancedAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $oauthLastRefreshAt = null;

    /**
     * When the refresh token this account is currently holding was issued.
     *
     * Not the same as {@see $oauthLastRefreshAt}, which moves every hour. This
     * moves only when the provider hands over a NEW refresh token, which for
     * Google means the moment somebody completed the consent screen — so it is
     * the age of the GRANT rather than of the access token.
     *
     * It exists to answer one question: how long did this sign-in last before
     * it died? Google expires refresh tokens issued by an OAuth app still in
     * "Testing" publishing status after about a week, and a self-hosted install
     * is usually left in Testing because publishing means verification. The
     * symptom is an account that stops every seven days for ever, with an
     * `invalid_grant` that says nothing about why — and the only thing that
     * distinguishes it from a revoked password is the interval.
     *
     * Null for every account connected before this was recorded, and that stays
     * null until the next reconnect. Nothing infers anything from a null.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $oauthGrantedAt = null;

    /**
     * How many hours the PREVIOUS sign-in lasted before it was revoked.
     *
     * Kept across the reconnect on purpose: it is the only evidence that
     * survives it, and the whole point is to be able to say "this will happen
     * again in a week" the moment somebody has just fixed it — which is the one
     * moment they are looking at the account and could act on the cause.
     *
     * One observation is enough to be worth mentioning and not enough to be
     * certain, and the wording says so.
     */
    #[ORM\Column(nullable: true)]
    public ?int $oauthPriorGrantHours = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $oauthLastRefreshError = null;

    /**
     * @var array<string, string>  graphFolderId => deltaLink
     */
    #[ORM\Column(type: Types::JSON)]
    public array $graphDeltaLinks = [];

    /**
     * Whether this mailbox honours Prefer: IdType="ImmutableId".
     * Null = not yet probed. False is survivable — dedup keys on the RFC
     * Message-ID — but means messages re-address on every folder move.
     */
    #[ORM\Column(nullable: true)]
    public ?bool $graphImmutableIds = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $pushEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $graphSubscriptionId = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $graphSubscriptionClientState = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $graphSubscriptionExpiresAt = null;

    public function __construct()
    {
        $this->aliases = new ArrayCollection();
        $this->mailboxes = new ArrayCollection();
        $this->messageThreads = new ArrayCollection();
        $this->messages = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Which calendar events extracted from this account's mail land on.
     *
     * Absent means the user's default calendar, which is where a person looks
     * for their own appointments — so this only has to exist for the user who
     * wants a particular mailbox's bookings kept apart, and the account's own
     * calendar (provisioned with the account) is the obvious thing to point it
     * at.
     */
    public const string SETTING_CALENDAR_TARGET = 'calendar.target_id';

    /**
     * How far back a backfill has actually walked, or absent if none has ever
     * finished. 0 means it reached the whole mailbox; a positive count is a
     * stopping point left over from the retired newest-N cap, and the mail
     * below it is still owed.
     */
    public const string SETTING_BACKFILL_TARGET = 'sync.backfill_target';

    /** When the last sync of this account began. See $syncBeganAt. */
    public const string SETTING_SYNC_BEGAN_AT = 'sync.began_at';

    /** When this account's first import last did something. See $importBeatAt. */
    public const string SETTING_IMPORT_BEAT_AT = 'sync.import_beat_at';

    /** How many messages the provider says the mailbox holds. See $importTotal. */
    public const string SETTING_IMPORT_TOTAL = 'sync.import_total';

    /** When the last backfill listing ran, to keep runs from overlapping. */
    public const string SETTING_BACKFILL_RAN_AT = 'sync.backfill_ran_at';

    /** Where a backfill listing that is under way has got to. See $backfillPageToken. */
    public const string SETTING_BACKFILL_PAGE_TOKEN = 'sync.backfill_page_token';

    /** Unfetched messages the listing under way has found so far. */
    public const string SETTING_BACKFILL_PENDING = 'sync.backfill_pending';

    /** Consecutive backfill listings that still found unfetched messages. */
    public const string SETTING_BACKFILL_ATTEMPTS = 'sync.backfill_attempts';

    /** The Microsoft folders whose history is still to be read. See $graphImport. */
    public const string SETTING_GRAPH_IMPORT = 'sync.graph_import';

    /** Removals a Microsoft import was not yet able to judge. See $graphRemovals. */
    public const string SETTING_GRAPH_REMOVALS = 'sync.graph_removals';

    /**
     * What this account does when a sender asks for a read receipt, for any
     * address that has no answer of its own.
     *
     * Absent means ReadReceiptMode::Never, and that default is the feature's
     * whole privacy posture rather than a convenience: a user who never opens
     * this panel must never emit a receipt. Every read of this setting goes
     * through ReadReceiptMode::fromSetting(), which turns anything it does not
     * recognise — absent, null, a value from a future version, a hand-edited
     * jsonb blob — into Never, so there is no shape of stored data that
     * accidentally starts answering.
     */
    public const string SETTING_READ_RECEIPT_DEFAULT = 'compose.read_receipt.default';

    /**
     * The per-alias override, keyed by alias id.
     *
     * Per alias rather than per account because that is the granularity people
     * actually want: a work address that answers receipts and a personal one
     * that never does are the same mailbox here, and one switch for both makes
     * the cautious answer the only usable one. Keyed into the existing jsonb
     * bag rather than given a column on EmailAlias, for the same reason the
     * signature setting is — see SETTING_CALENDAR_TARGET above, and note that
     * a deleted alias simply leaves a key nothing ever reads again.
     */
    public static function readReceiptAliasSetting(int $aliasId): string
    {
        return 'compose.read_receipt.alias.' . $aliasId;
    }

    /**
     * The HTML signature this account signs with, for any address that has no
     * signature of its own.
     *
     * Absent means no signature at all. The stored value is sanitised HTML —
     * SignatureProvider is the only writer and MailBodySanitizer::sanitizeFragment()
     * is what it writes through, because this string is injected verbatim into
     * every outgoing message and the settings panel that fills it is a
     * contenteditable, which is to say user input with a paste buffer attached.
     */
    public const string SETTING_SIGNATURE = 'compose.signature';

    /**
     * The per-alias signature override, keyed by alias id.
     *
     * Same shape and same reasoning as readReceiptAliasSetting() above — per
     * alias because one mailbox holding a work address and a personal one
     * wants two different sign-offs, and in the jsonb bag rather than in a
     * column on EmailAlias because neither needs a migration to exist.
     *
     * The key's PRESENCE is the state, not its value. An absent key means this
     * alias has no opinion and inherits the account signature; a key holding
     * the empty string means this alias deliberately signs with nothing. Those
     * are different answers and writers must use unsetSetting() for the first
     * rather than storing null — see unsetSetting() below.
     */
    public static function signatureAliasSetting(int $aliasId): string
    {
        return 'compose.signature.alias.' . $aliasId;
    }

    /**
     * A sync that worked, recorded the same way a calendar records one.
     *
     * Mirrors Calendar::recordSyncSuccess() down to the name, and for the
     * reason that class gives: a connection that has quietly stopped working
     * should say so in the same way wherever it is listed, and two spellings of
     * "it is fine now" is how one of them ends up not clearing the error.
     *
     * One success earns the whole count back. Decaying it instead would leave a
     * mailbox that recovered still on the health page, with nothing on screen
     * to explain why it looks broken.
     */
    public function recordSyncSuccess(): void
    {
        $this->lastSyncedAt      = new DateTimeImmutable();
        $this->lastSyncError     = null;
        $this->syncFailureCount  = 0;
    }

    /**
     * A sync that did not, and how many in a row that makes.
     *
     * The count is what lets the health page stay quiet about a dropped
     * connection and speak up about a mailbox that has been failing all day —
     * see AccountHealthInspector, which is the only reader of it.
     *
     * Truncated: a provider stack trace is not a message, and this string is
     * shown to a person.
     */
    public function recordSyncFailure(string $reason): void
    {
        $this->lastSyncError = mb_substr($reason, 0, 500);
        ++$this->syncFailureCount;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        if (true === array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }

        return $default;
    }

    public function setSetting(string $key, mixed $value): static
    {
        $this->settings[$key] = $value;

        return $this;
    }

    /**
     * Remove a key entirely, which is not the same as setting it to null.
     *
     * Readers that layer settings — a per-alias override on top of an account
     * default — distinguish "this level has no opinion" from "this level says
     * no" by whether the key is there at all. Writing null would make an alias
     * that means "follow the default" indistinguishable from one that means
     * "never", and the default would stop reaching it.
     */
    public function unsetSetting(string $key): static
    {
        unset($this->settings[$key]);

        return $this;
    }

    /**
     * Which provider this account talks to, read off the pair of columns that
     * actually record it.
     *
     * Both stay methods: there is no boolean column here. They answer a
     * question about $authType and $oauthProvider, which is an interpretation
     * of two strings rather than the plain read $isActive and $pushEnabled are.
     */
    public function isMicrosoft(): bool
    {
        if (AuthType::OAuth2->value !== $this->authType) {
            return false;
        }

        return MailProvider::Microsoft->value === $this->oauthProvider;
    }

    public function isGmail(): bool
    {
        return AuthType::OAuth2->value === $this->authType
            && MailProvider::Google->value === $this->oauthProvider;
    }

    /**
     * How far back a completed backfill reached: 0 for the whole mailbox, a
     * positive count for the newest N, null when none has ever finished.
     *
     * Virtual, so there is no column behind it — the value lives in the
     * settings bag, and Doctrine refuses to map a property whose hooks do not
     * touch a backing store.
     */
    public ?int $backfillTarget {
        get {
            $target = $this->getSetting(self::SETTING_BACKFILL_TARGET);

            return null === $target ? null : max(0, (int) $target);
        }
        set (?int $target) {
            $this->setSetting(
                self::SETTING_BACKFILL_TARGET,
                null === $target ? null : max(0, $target),
            );
        }
    }

    /**
     * Virtual for the same reason as $backfillTarget, and stored as a Unix timestamp
     * because the bag is JSON and a DateTimeImmutable does not survive it.
     */
    public ?DateTimeImmutable $backfillRanAt {
        get {
            $timestamp = $this->getSetting(self::SETTING_BACKFILL_RAN_AT);

            if (null === $timestamp) {
                return null;
            }

            return (new DateTimeImmutable())->setTimestamp((int) $timestamp);
        }
        set (?DateTimeImmutable $ranAt) {
            $this->setSetting(
                self::SETTING_BACKFILL_RAN_AT,
                $ranAt?->getTimestamp(),
            );
        }
    }

    /**
     * The token for the next page of a backfill listing that is under way, or
     * null between listings.
     *
     * A listing is walked a page at a time by MailImporter, each page a job of
     * its own, and this is what one job leaves for the next. Null therefore
     * means "the next page asked for is the first of a new listing", which is
     * when the hourly cooldown is checked.
     */
    public ?string $backfillPageToken {
        get {
            $token = $this->getSetting(self::SETTING_BACKFILL_PAGE_TOKEN);

            return is_string($token) && '' !== $token ? $token : null;
        }
        set (?string $token) {
            $this->setSetting(self::SETTING_BACKFILL_PAGE_TOKEN, $token);
        }
    }

    /**
     * How many unfetched messages the listing under way has turned up, across
     * the pages walked so far. Handed to the settling at the end, which used
     * to be given the count of one whole listing made in one go.
     */
    public int $backfillPending {
        get => max(0, (int) $this->getSetting(self::SETTING_BACKFILL_PENDING, 0));
        set (int $pending) {
            $this->setSetting(self::SETTING_BACKFILL_PENDING, max(0, $pending));
        }
    }

    /**
     * When the last sync of this account began, whether or not it went through.
     *
     * Beside $lastSyncedAt, which is when one last FINISHED cleanly, because
     * the pair answers a question neither can alone: was there a sync that
     * started after a given moment and succeeded? See isSyncedSince().
     *
     * Virtual and a Unix timestamp, like $backfillRanAt.
     */
    public ?DateTimeImmutable $syncBeganAt {
        get {
            $timestamp = $this->getSetting(self::SETTING_SYNC_BEGAN_AT);

            return null === $timestamp ? null : (new DateTimeImmutable())->setTimestamp((int) $timestamp);
        }
        set (?DateTimeImmutable $beganAt) {
            $this->setSetting(self::SETTING_SYNC_BEGAN_AT, $beganAt?->getTimestamp());
        }
    }

    /**
     * Whether a sync that began after $requestedAt has already gone through.
     *
     * The test for a redundant sync request. "Began after" is the whole of it:
     * a sync that was already running when the request was made may have read
     * the server before whatever prompted the request arrived, so its success
     * says nothing about it. One that started later has seen it.
     *
     * Strictly after, to the second. A sync beginning in the same second as
     * the request is not counted, which costs one repeated sync now and then
     * and never costs mail.
     */
    public function isSyncedSince(int $requestedAt): bool
    {
        $beganAt = $this->syncBeganAt;

        return null !== $beganAt
            && $beganAt->getTimestamp() > $requestedAt
            && null !== $this->lastSyncedAt
            && $this->lastSyncedAt >= $beganAt
            && null === $this->lastSyncError;
    }

    /**
     * When this account's first import last did something: planned a folder,
     * stored a page.
     *
     * Two readers. The import's own safety net, which starts a fresh chain of
     * pages only when this has gone quiet (see MailImporter::ensureRunning()),
     * and the progress line in the topbar, which says "waiting" rather than
     * showing a bar that has stopped moving.
     *
     * Virtual and a Unix timestamp, like $backfillRanAt.
     */
    public ?DateTimeImmutable $importBeatAt {
        get {
            $timestamp = $this->getSetting(self::SETTING_IMPORT_BEAT_AT);

            return null === $timestamp ? null : (new DateTimeImmutable())->setTimestamp((int) $timestamp);
        }
        set (?DateTimeImmutable $beatAt) {
            $this->setSetting(self::SETTING_IMPORT_BEAT_AT, $beatAt?->getTimestamp());
        }
    }

    /**
     * How many messages the provider says this mailbox holds, as of the moment
     * its import was planned. Gmail answers that in one call and Microsoft in
     * its folder list; an IMAP account's total is the sum of its folders'
     * (Mailbox::$importTotal).
     *
     * For the progress line and nothing else. It is a snapshot and is allowed
     * to be a little wrong — mail arrives and is deleted while an import runs.
     */
    public ?int $importTotal {
        get {
            $total = $this->getSetting(self::SETTING_IMPORT_TOTAL);

            return null === $total ? null : max(0, (int) $total);
        }
        set (?int $total) {
            $this->setSetting(self::SETTING_IMPORT_TOTAL, $total);
        }
    }

    /**
     * The Microsoft folders whose history has not been read to its end, in the
     * order they are to be read.
     *
     * A Microsoft folder is followed by a delta link (see $graphDeltaLinks),
     * and the only way to be given one is to enumerate the folder once. That
     * enumeration IS the import, so a folder is in exactly one of two places:
     * it has a delta link and the sync follows it, or it has an entry here and
     * MailImporter is reading it a page at a time.
     *
     * Each entry is the folder's id, the moment it was planned — the sync asks
     * for mail received since then, because a folder with no delta link has no
     * other way to say that something new arrived — and the link to the next
     * page of an enumeration that is under way, null before the first.
     *
     * A list and not a map keyed by folder id: the order is the point, and a
     * JSON object does not promise to keep one.
     *
     * @var list<array{folder: string, since: int, next: string|null}>
     */
    public array $graphImport {
        get {
            $entries = $this->getSetting(self::SETTING_GRAPH_IMPORT, []);
            $clean   = [];

            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (false === is_array($entry) || false === is_string($entry['folder'] ?? null)) {
                    continue;
                }

                $next = $entry['next'] ?? null;

                $clean[] = [
                    'folder' => $entry['folder'],
                    'since'  => (int) ($entry['since'] ?? 0),
                    'next'   => is_string($next) && '' !== $next ? $next : null,
                ];
            }

            return $clean;
        }
        set (array $entries) {
            $this->setSetting(self::SETTING_GRAPH_IMPORT, array_values($entries));
        }
    }

    /**
     * Messages Microsoft reported as gone from a folder while another folder
     * was still being imported.
     *
     * "Gone from a folder" is a deletion only if the message arrived in no
     * other, and a folder whose history is still being read cannot say whether
     * it did. A removal is reported once, so the ones that could not be judged
     * are kept here and judged by the first sync that has every folder's
     * answer. See GraphApiSyncer::sync().
     *
     * @var list<string>
     */
    public array $graphRemovals {
        get {
            $ids = $this->getSetting(self::SETTING_GRAPH_REMOVALS, []);

            return array_values(array_filter(is_array($ids) ? $ids : [], is_string(...)));
        }
        set (array $ids) {
            $this->setSetting(self::SETTING_GRAPH_REMOVALS, array_values($ids));
        }
    }

    /** Whether a Microsoft account has folders whose history is still to be read. */
    public function needsGraphImport(): bool
    {
        return [] !== $this->graphImport;
    }

    /** Virtual for the same reason as $backfillTarget. */
    public int $backfillAttempts {
        get => max(0, (int) $this->getSetting(self::SETTING_BACKFILL_ATTEMPTS, 0));
        set (int $attempts) {
            $this->setSetting(self::SETTING_BACKFILL_ATTEMPTS, max(0, $attempts));
        }
    }

    /**
     * Whether a backfill still has ground to cover.
     *
     * Only a backfill that reached 0 — the whole mailbox — is finished. Null is
     * one that has never completed, and a positive count is one that stopped at
     * the newest N because the retired sync cap said so. Neither is complete,
     * so both read as still owing: with no cap left to satisfy, 0 is the only
     * value that can mean "everything is in".
     */
    public function needsBackfill(): bool
    {
        return 0 !== $this->backfillTarget;
    }

    /**
     * Only Gmail and Microsoft can mirror label structure. On plain IMAP a
     * label is a physical folder, so create/delete would move real mail —
     * a different and riskier operation than this toggle promises.
     */
    public function supportsLabelSync(): bool
    {
        return true === $this->isGmail() || true === $this->isMicrosoft();
    }

    public function addAlias(EmailAlias $alias): static
    {
        if (false === $this->aliases->contains($alias)) {
            $this->aliases->add($alias);
        }

        return $this;
    }

    public function removeAlias(EmailAlias $alias): static
    {
        $this->aliases->removeElement($alias);

        return $this;
    }

    /**
     * Virtual, so there is no column behind it — the aliases are the state and
     * this is only a view of them.
     */
    public ?EmailAlias $primaryAlias {
        get {
            foreach ($this->aliases as $alias) {
                if (EmailAliasStatus::Primary === $alias->status) {
                    return $alias;
                }
            }

            return null;
        }
    }

    /**
     * The address to show in the UI and default the From to. Falls back to the
     * legacy email/username while an account has no aliases yet (pre-seed).
     *
     * Virtual for the same reason as $primaryAlias.
     */
    public ?string $displayAddress {
        get {
            $primary = $this->primaryAlias;

            if (null !== $primary) {
                return $primary->address;
            }

            return $this->email ?? $this->username;
        }
    }

    /**
     * Sendable aliases (Primary first), for the From dropdown.
     *
     * Virtual for the same reason as $primaryAlias.
     *
     * @var list<EmailAlias>
     */
    public array $sendableAliases {
        get {
            $sendable = [];

            foreach ($this->aliases as $alias) {
                if (true === $alias->status->isSendable()) {
                    $sendable[] = $alias;
                }
            }

            usort(
                $sendable,
                static fn (EmailAlias $a, EmailAlias $b): int
                => (EmailAliasStatus::Primary === $b->status ? 1 : 0)
                    - (EmailAliasStatus::Primary === $a->status ? 1 : 0),
            );

            return $sendable;
        }
    }

    /**
     * Lowercased addresses that count as "this account" for ownership matching
     * and reply self-exclusion. Once any alias exists it is authoritative
     * (so an Inactive alias genuinely stops being claimed); before seeding it
     * falls back to the legacy email/username so behaviour is unchanged.
     *
     * Virtual for the same reason as $primaryAlias.
     *
     * @var list<string>
     */
    public array $ownedAddresses {
        get {
            $owned = [];

            foreach ($this->aliases as $alias) {
                if (true === $alias->status->countsForOwnership()) {
                    $owned[] = $alias->address;
                }
            }

            if (count($owned) > 0) {
                return array_values(array_unique($owned));
            }

            $fallback = array_filter([
                null !== $this->email ? strtolower($this->email) : null,
                null !== $this->username ? strtolower($this->username) : null,
            ]);

            return array_values(array_unique($fallback));
        }
    }
}
