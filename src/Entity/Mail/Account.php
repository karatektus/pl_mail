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
//#[Broadcast]
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

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastSyncedAt = null;



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
     * Last time this mailbox was found to have actually CHANGED — the moment
     * the stored historyId advanced, whoever noticed it.
     *
     * The counterpart gmailLastPushAt needed to mean anything. Elapsed silence
     * on its own cannot tell a broken push from a quiet mailbox, and every
     * threshold that tries is a guess that either cries wolf at people who get
     * little mail or stays quiet for a day and a half at people whose push has
     * died. This column removes the guess: Gmail pushes on ANY history change,
     * so a history that advanced well after the last push is a change that push
     * failed to announce — evidence, not an inference.
     *
     * A genuinely quiet mailbox never advances its history, so it never
     * produces this evidence and never raises anything, at any hour. That is
     * the false-alarm case handled by construction rather than by a constant.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $gmailHistoryAdvancedAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $oauthLastRefreshAt = null;

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

    public function addMailbox(Mailbox $mailbox): static
    {
        if (!$this->mailboxes->contains($mailbox)) {
            $this->mailboxes->add($mailbox);
            $mailbox->account = $this;
        }

        return $this;
    }

    public function removeMailbox(Mailbox $mailbox): static
    {
        if ($this->mailboxes->removeElement($mailbox)) {
            // set the owning side to null (unless already changed)
            if ($mailbox->account === $this) {
                $mailbox->account = null;
            }
        }

        return $this;
    }

    public function addMessageThread(MessageThread $messageThread): static
    {
        if (!$this->messageThreads->contains($messageThread)) {
            $this->messageThreads->add($messageThread);
            $messageThread->account = $this;
        }

        return $this;
    }

    public function removeMessageThread(MessageThread $messageThread): static
    {
        if ($this->messageThreads->removeElement($messageThread)) {
            // set the owning side to null (unless already changed)
            if ($messageThread->account === $this) {
                $messageThread->account = null;
            }
        }

        return $this;
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

    /** When the last backfill listing ran, to keep runs from overlapping. */
    public const string SETTING_BACKFILL_RAN_AT = 'sync.backfill_ran_at';

    /** Consecutive backfill listings that still found unfetched messages. */
    public const string SETTING_BACKFILL_ATTEMPTS = 'sync.backfill_attempts';

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

    /**
     * Stays a method rather than becoming a property: this reads a timestamp
     * and answers a question about it against the clock, which is an
     * interpretation, not the mapped boolean column that $isActive is.
     */
    public function isGmailWatchActive(): bool
    {
        $expiry = $this->gmailWatchExpiry;

        if (null === $expiry) {
            return false;
        }

        return $expiry > new DateTimeImmutable();
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
