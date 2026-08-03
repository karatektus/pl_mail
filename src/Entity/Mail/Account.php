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
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
//#[Broadcast]
class Account extends AccountModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $sortOrder = 0;

    #[ORM\Column]
    public bool $isPrimary = false;

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

    #[ORM\Column]
    public ?bool $isActive = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    public ?DateTimeImmutable $updatedAt = null;

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

    /** Settings-bag key for the provider label-sync toggle. */
    public const string SETTING_LABEL_SYNC = 'labels.sync_to_provider';

    /**
     * Which calendar events extracted from this account's mail land on.
     *
     * Absent means the account's own calendar, which CalendarProvisioner
     * creates with the account — so this only has to exist for the user who
     * wants everything in one place, or one kind of thing somewhere else.
     */
    public const string SETTING_CALENDAR_TARGET = 'calendar.target_id';

    /** Settings-bag key for the newest-N sync cap. */
    public const string SETTING_SYNC_LIMIT = 'sync.message_limit';

    /** Offered in the UI. 0 means no cap. */
    public const array SYNC_LIMIT_CHOICES = [0, 500, 1000, 2000, 5000, 10000, 25000];

    /**
     * The cap a backfill has actually walked back to, or absent if none has
     * ever finished. Distinct from SETTING_SYNC_LIMIT, which is what the user
     * asked for: the gap between the two is what still needs fetching.
     */
    public const string SETTING_BACKFILL_TARGET = 'sync.backfill_target';

    /** When the last backfill listing ran, to keep runs from overlapping. */
    public const string SETTING_BACKFILL_RAN_AT = 'sync.backfill_ran_at';

    /** Consecutive backfill listings that still found unfetched messages. */
    public const string SETTING_BACKFILL_ATTEMPTS = 'sync.backfill_attempts';

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
     * Whether label create/rename/delete is mirrored to the provider.
     *
     * Lives in the free-form settings bag rather than its own column: it is a
     * user preference with a safe default, not something queried or indexed.
     * That is also why it stays a method — there is no boolean column here to
     * expose, only an untyped bag entry that may be missing or hold anything,
     * and reading it is an interpretation rather than a plain read. Writers go
     * through setSetting(self::SETTING_LABEL_SYNC, …), which is all a setter
     * here would have been.
     */
    public function isLabelSyncEnabled(): bool
    {
        return true === $this->getSetting(self::SETTING_LABEL_SYNC, false);
    }

    /**
     * How many of the newest messages a sync run may pull, or 0 for no cap.
     *
     * Backfilling a large mailbox from scratch is the slow case this exists
     * for: a 60k-message Gmail account is hours of API calls before the UI is
     * usable, while the newest couple of thousand are what the user actually
     * reads. Older mail is not queued for later, it is simply not fetched
     * yet — raising the cap lets a later run walk further back, tracked by
     * $backfillTarget.
     *
     * Virtual, so there is no column behind it — the value lives in the
     * settings bag, and Doctrine refuses to map a property whose hooks do not
     * touch a backing store.
     */
    public int $syncLimit {
        get => max(0, (int) $this->getSetting(self::SETTING_SYNC_LIMIT, 0));
        set (int $limit) {
            $this->setSetting(self::SETTING_SYNC_LIMIT, max(0, $limit));
        }
    }

    /**
     * How far back a completed backfill reached: 0 for the whole mailbox, a
     * positive count for the newest N, null when none has ever finished.
     *
     * Virtual for the same reason as $syncLimit.
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
     * Virtual for the same reason as $syncLimit, and stored as a Unix timestamp
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

    /** Virtual for the same reason as $syncLimit. */
    public int $backfillAttempts {
        get => max(0, (int) $this->getSetting(self::SETTING_BACKFILL_ATTEMPTS, 0));
        set (int $attempts) {
            $this->setSetting(self::SETTING_BACKFILL_ATTEMPTS, max(0, $attempts));
        }
    }

    /**
     * Whether a backfill still has ground to cover.
     *
     * True until one has completed, and true again whenever the cap is raised
     * past what the last completed one reached — that is what makes changing
     * the setting after the first sync do anything at all.
     */
    public function needsBackfill(): bool
    {
        $completed = $this->backfillTarget;

        if (null === $completed) {
            return true;
        }

        // A completed uncapped backfill has the whole mailbox; nothing can
        // widen that.
        if (0 === $completed) {
            return false;
        }

        $limit = $this->syncLimit;

        return 0 === $limit || $limit > $completed;
    }

    /**
     * Microsoft is excluded: Graph enumerates a folder through a delta query
     * whose deltaLink only arrives after the final page, and the pages are not
     * newest-first. Stopping early would neither give a usable cursor nor the
     * newest N, so the setting is not offered rather than silently ignored.
     */
    public function supportsSyncLimit(): bool
    {
        return false === $this->isMicrosoft();
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
