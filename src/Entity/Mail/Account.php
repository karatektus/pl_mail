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
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column]
    private bool $isPrimary = false;

    #[ORM\ManyToOne(inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $usr = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imapHost = null;

    #[ORM\Column(nullable: true)]
    private ?int $imapPort = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $imapEncryption = null;

    #[ORM\Column(length: 255)]
    private ?string $username = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpHost = null;

    #[ORM\Column(nullable: true)]
    private ?int $smtpPort = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $smtpEncryption = null;

    #[ORM\Column(length: 20)]
    private ?string $authType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $oauthProvider = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    private ?string $oauthAccessToken = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    private ?string $oauthRefreshToken = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $oauthTokenExpiry = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gmailHistoryId = null;

    /**
     * When the users.watch() registration for this mailbox expires.
     * Google watch registrations last at most 7 days and must be renewed.
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $gmailWatchExpiry = null;

    /**
     * The resource name returned by users.watch() — stored so we can call
     * users.stop() if the account is disconnected.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $gmailWatchResourceName = null;

    /**
     * @var Collection<int, EmailAlias>
     */
    #[ORM\OneToMany(targetEntity: EmailAlias::class, mappedBy: 'account', cascade: ['persist'], orphanRemoval: true)]
    private Collection $aliases;

    /**
     * @var Collection<int, Mailbox>
     */
    #[ORM\OneToMany(targetEntity: Mailbox::class, mappedBy: 'account')]
    private Collection $mailboxes;

    /**
     * @var Collection<int, MessageThread>
     */
    #[ORM\OneToMany(targetEntity: MessageThread::class, mappedBy: 'account')]
    private Collection $messageThreads;

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
    private Collection $messages;

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
    private ?DateTimeImmutable $gmailLastPushAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $oauthLastRefreshAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oauthLastRefreshError = null;

    #[ORM\Column(type: Types::JSON)]
    private array $graphDeltaLinks = [];

    /**
     * Whether this mailbox honours Prefer: IdType="ImmutableId".
     * Null = not yet probed. False is survivable — dedup keys on the RFC
     * Message-ID — but means messages re-address on every folder move.
     */
    #[ORM\Column(nullable: true)]
    private ?bool $graphImmutableIds = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $pushEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $graphSubscriptionId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $graphSubscriptionClientState = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $graphSubscriptionExpiresAt = null;

    public function __construct()
    {
        $this->aliases = new ArrayCollection();
        $this->mailboxes = new ArrayCollection();
        $this->messageThreads = new ArrayCollection();
        $this->messages = new ArrayCollection();
        $this->setCreatedAt(new DateTimeImmutable());
        $this->setUpdatedAt(new DateTimeImmutable());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsr(): ?User
    {
        return $this->usr;
    }

    public function setUsr(?User $usr): static
    {
        $this->usr = $usr;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getImapHost(): ?string
    {
        return $this->imapHost;
    }

    public function setImapHost(?string $imapHost): static
    {
        $this->imapHost = $imapHost;

        return $this;
    }

    public function getImapPort(): ?int
    {
        return $this->imapPort;
    }

    public function setImapPort(?int $imapPort): static
    {
        $this->imapPort = $imapPort;

        return $this;
    }

    public function getImapEncryption(): ?string
    {
        return $this->imapEncryption;
    }

    public function setImapEncryption(?string $imapEncryption): static
    {
        $this->imapEncryption = $imapEncryption;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): static
    {
        $this->smtpHost = $smtpHost;

        return $this;
    }

    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): static
    {
        $this->smtpPort = $smtpPort;

        return $this;
    }

    public function getSmtpEncryption(): ?string
    {
        return $this->smtpEncryption;
    }

    public function setSmtpEncryption(?string $smtpEncryption): static
    {
        $this->smtpEncryption = $smtpEncryption;

        return $this;
    }

    public function getAuthType(): ?string
    {
        return $this->authType;
    }

    public function setAuthType(string $authType): static
    {
        $this->authType = $authType;

        return $this;
    }

    public function getOauthProvider(): ?string
    {
        return $this->oauthProvider;
    }

    public function setOauthProvider(?string $oauthProvider): static
    {
        $this->oauthProvider = $oauthProvider;

        return $this;
    }

    public function getOauthAccessToken(): ?string
    {
        return $this->oauthAccessToken;
    }

    public function setOauthAccessToken(?string $oauthAccessToken): static
    {
        $this->oauthAccessToken = $oauthAccessToken;

        return $this;
    }

    public function getOauthRefreshToken(): ?string
    {
        return $this->oauthRefreshToken;
    }

    public function setOauthRefreshToken(?string $oauthRefreshToken): static
    {
        $this->oauthRefreshToken = $oauthRefreshToken;

        return $this;
    }

    public function getOauthTokenExpiry(): ?DateTimeImmutable
    {
        return $this->oauthTokenExpiry;
    }

    public function setOauthTokenExpiry(?DateTimeImmutable $oauthTokenExpiry): static
    {
        $this->oauthTokenExpiry = $oauthTokenExpiry;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getLastSyncedAt(): ?DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): static
    {
        $this->isPrimary = $isPrimary;
        return $this;
    }

    /**
     * @return Collection<int, Mailbox>
     */
    public function getMailboxes(): Collection
    {
        return $this->mailboxes;
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

    /**
     * @return Collection<int, MessageThread>
     */
    public function getMessageThreads(): Collection
    {
        return $this->messageThreads;
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
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function getGmailHistoryId(): ?string
    {
        return $this->gmailHistoryId;
    }

    public function setGmailHistoryId(?string $gmailHistoryId): static
    {
        $this->gmailHistoryId = $gmailHistoryId;
        return $this;
    }

    public function getGmailWatchExpiry(): ?DateTimeImmutable
    {
        return $this->gmailWatchExpiry;
    }

    public function setGmailWatchExpiry(?DateTimeImmutable $gmailWatchExpiry): static
    {
        $this->gmailWatchExpiry = $gmailWatchExpiry;
        return $this;
    }

    public function getGmailWatchResourceName(): ?string
    {
        return $this->gmailWatchResourceName;
    }

    public function setGmailWatchResourceName(?string $gmailWatchResourceName): static
    {
        $this->gmailWatchResourceName = $gmailWatchResourceName;
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

    public function getGmailLastPushAt(): ?DateTimeImmutable
    {
        return $this->gmailLastPushAt;
    }

    public function setGmailLastPushAt(?DateTimeImmutable $gmailLastPushAt): static
    {
        $this->gmailLastPushAt = $gmailLastPushAt;

        return $this;
    }

    public function getOauthLastRefreshAt(): ?DateTimeImmutable
    {
        return $this->oauthLastRefreshAt;
    }

    public function setOauthLastRefreshAt(?DateTimeImmutable $oauthLastRefreshAt): static
    {
        $this->oauthLastRefreshAt = $oauthLastRefreshAt;

        return $this;
    }

    public function getOauthLastRefreshError(): ?string
    {
        return $this->oauthLastRefreshError;
    }

    public function setOauthLastRefreshError(?string $oauthLastRefreshError): static
    {
        $this->oauthLastRefreshError = $oauthLastRefreshError;

        return $this;
    }
    /**
     * @return array<string, string>  graphFolderId => deltaLink
     */
    public function getGraphDeltaLinks(): array
    {
        return $this->graphDeltaLinks;
    }

    /**
     * @param array<string, string> $graphDeltaLinks
     */
    public function setGraphDeltaLinks(array $graphDeltaLinks): static
    {
        $this->graphDeltaLinks = $graphDeltaLinks;

        return $this;
    }

    public function getGraphImmutableIds(): ?bool
    {
        return $this->graphImmutableIds;
    }

    public function setGraphImmutableIds(?bool $graphImmutableIds): static
    {
        $this->graphImmutableIds = $graphImmutableIds;

        return $this;
    }

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
     */
    public function isLabelSyncEnabled(): bool
    {
        return true === $this->getSetting(self::SETTING_LABEL_SYNC, false);
    }

    public function setLabelSyncEnabled(bool $enabled): static
    {
        return $this->setSetting(self::SETTING_LABEL_SYNC, $enabled);
    }

    /**
     * How many of the newest messages a sync run may pull, or 0 for no cap.
     *
     * Backfilling a large mailbox from scratch is the slow case this exists
     * for: a 60k-message Gmail account is hours of API calls before the UI is
     * usable, while the newest couple of thousand are what the user actually
     * reads. Older mail is not queued for later, it is simply not fetched
     * yet — raising the cap lets a later run walk further back, tracked by
     * SETTING_BACKFILL_TARGET.
     */
    public function getSyncLimit(): int
    {
        return max(0, (int) $this->getSetting(self::SETTING_SYNC_LIMIT, 0));
    }

    public function setSyncLimit(int $limit): static
    {
        return $this->setSetting(self::SETTING_SYNC_LIMIT, max(0, $limit));
    }

    /**
     * How far back a completed backfill reached: 0 for the whole mailbox, a
     * positive count for the newest N, null when none has ever finished.
     */
    public function getBackfillTarget(): ?int
    {
        $target = $this->getSetting(self::SETTING_BACKFILL_TARGET);

        return null === $target ? null : max(0, (int) $target);
    }

    public function setBackfillTarget(?int $target): static
    {
        return $this->setSetting(
            self::SETTING_BACKFILL_TARGET,
            null === $target ? null : max(0, $target),
        );
    }

    public function getBackfillRanAt(): ?DateTimeImmutable
    {
        $timestamp = $this->getSetting(self::SETTING_BACKFILL_RAN_AT);

        if (null === $timestamp) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp((int) $timestamp);
    }

    public function setBackfillRanAt(?DateTimeImmutable $ranAt): static
    {
        return $this->setSetting(
            self::SETTING_BACKFILL_RAN_AT,
            $ranAt?->getTimestamp(),
        );
    }

    public function getBackfillAttempts(): int
    {
        return max(0, (int) $this->getSetting(self::SETTING_BACKFILL_ATTEMPTS, 0));
    }

    public function setBackfillAttempts(int $attempts): static
    {
        return $this->setSetting(self::SETTING_BACKFILL_ATTEMPTS, max(0, $attempts));
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
        $completed = $this->getBackfillTarget();

        if (null === $completed) {
            return true;
        }

        // A completed uncapped backfill has the whole mailbox; nothing can
        // widen that.
        if (0 === $completed) {
            return false;
        }

        $limit = $this->getSyncLimit();

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

    public function isPushEnabled(): bool
    {
        return $this->pushEnabled;
    }

    public function setPushEnabled(bool $pushEnabled): static
    {
        $this->pushEnabled = $pushEnabled;

        return $this;
    }

    public function getGraphSubscriptionId(): ?string
    {
        return $this->graphSubscriptionId;
    }

    public function setGraphSubscriptionId(?string $graphSubscriptionId): static
    {
        $this->graphSubscriptionId = $graphSubscriptionId;

        return $this;
    }

    public function getGraphSubscriptionClientState(): ?string
    {
        return $this->graphSubscriptionClientState;
    }

    public function setGraphSubscriptionClientState(?string $graphSubscriptionClientState): static
    {
        $this->graphSubscriptionClientState = $graphSubscriptionClientState;

        return $this;
    }

    public function getGraphSubscriptionExpiresAt(): ?\DateTimeImmutable
    {
        return $this->graphSubscriptionExpiresAt;
    }

    public function setGraphSubscriptionExpiresAt(?\DateTimeImmutable $graphSubscriptionExpiresAt): static
    {
        $this->graphSubscriptionExpiresAt = $graphSubscriptionExpiresAt;

        return $this;
    }

    public function isGmailWatchActive(): bool
    {
        $expiry = $this->gmailWatchExpiry;

        if (null === $expiry) {
            return false;
        }

        return $expiry > new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, EmailAlias>
     */
    public function getAliases(): Collection
    {
        return $this->aliases;
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

    public function getPrimaryAlias(): ?EmailAlias
    {
        foreach ($this->aliases as $alias) {
            if (EmailAliasStatus::Primary === $alias->status) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * The address to show in the UI and default the From to. Falls back to the
     * legacy email/username while an account has no aliases yet (pre-seed).
     */
    public function getDisplayAddress(): ?string
    {
        $primary = $this->getPrimaryAlias();

        if (null !== $primary) {
            return $primary->address;
        }

        return $this->getEmail() ?? $this->getUsername();
    }

    /**
     * Sendable aliases (Primary first), for the From dropdown.
     *
     * @return list<EmailAlias>
     */
    public function getSendableAliases(): array
    {
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

    /**
     * Lowercased addresses that count as "this account" for ownership matching
     * and reply self-exclusion. Once any alias exists it is authoritative
     * (so an Inactive alias genuinely stops being claimed); before seeding it
     * falls back to the legacy email/username so behaviour is unchanged.
     *
     * @return list<string>
     */
    public function getOwnedAddresses(): array
    {
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
            null !== $this->getEmail() ? strtolower($this->getEmail()) : null,
            null !== $this->getUsername() ? strtolower($this->getUsername()) : null,
        ]);

        return array_values(array_unique($fallback));
    }
}
