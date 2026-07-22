<?php

namespace App\Entity;

use App\Domain\Enum\MailProvider;
use App\Domain\Model\AccountModel;
use App\Enum\AuthType;
use App\Repository\AccountRepository;
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

    #[ORM\Column(length: 255)]
    private ?string $imapHost = null;

    #[ORM\Column]
    private ?int $imapPort = null;

    #[ORM\Column(length: 20)]
    private ?string $imapEncryption = null;

    #[ORM\Column(length: 255)]
    private ?string $username = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oauthAccessToken = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oauthRefreshToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $oauthTokenExpiry = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gmailHistoryId = null;

    /**
     * When the users.watch() registration for this mailbox expires.
     * Google watch registrations last at most 7 days and must be renewed.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $gmailWatchExpiry = null;

    /**
     * The resource name returned by users.watch() — stored so we can call
     * users.stop() if the account is disconnected.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $gmailWatchResourceName = null;

    /**
     * @var Collection<int, Mailbox>
     */
    #[ORM\OneToMany(targetEntity: Mailbox::class, mappedBy: 'account', cascade: ['remove'], orphanRemoval: true)]
    private Collection $mailboxes;

    /**
     * @var Collection<int, MessageThread>
     */
    #[ORM\OneToMany(targetEntity: MessageThread::class, mappedBy: 'account', cascade: ['remove'], orphanRemoval: true)]
    private Collection $messageThreads;

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
    private ?\DateTimeImmutable $gmailLastPushAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $oauthLastRefreshAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oauthLastRefreshError = null;

    public function __construct()
    {
        $this->mailboxes = new ArrayCollection();
        $this->messageThreads = new ArrayCollection();
        $this->setCreatedAt(new \DateTimeImmutable());
        $this->setUpdatedAt(new \DateTimeImmutable());
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

    public function setImapHost(string $imapHost): static
    {
        $this->imapHost = $imapHost;

        return $this;
    }

    public function getImapPort(): ?int
    {
        return $this->imapPort;
    }

    public function setImapPort(int $imapPort): static
    {
        $this->imapPort = $imapPort;

        return $this;
    }

    public function getImapEncryption(): ?string
    {
        return $this->imapEncryption;
    }

    public function setImapEncryption(string $imapEncryption): static
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

    public function getOauthTokenExpiry(): ?\DateTimeImmutable
    {
        return $this->oauthTokenExpiry;
    }

    public function setOauthTokenExpiry(?\DateTimeImmutable $oauthTokenExpiry): static
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

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
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
            $mailbox->setAccount($this);
        }

        return $this;
    }

    public function removeMailbox(Mailbox $mailbox): static
    {
        if ($this->mailboxes->removeElement($mailbox)) {
            // set the owning side to null (unless already changed)
            if ($mailbox->getAccount() === $this) {
                $mailbox->setAccount(null);
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
            $messageThread->setAccount($this);
        }

        return $this;
    }

    public function removeMessageThread(MessageThread $messageThread): static
    {
        if ($this->messageThreads->removeElement($messageThread)) {
            // set the owning side to null (unless already changed)
            if ($messageThread->getAccount() === $this) {
                $messageThread->setAccount(null);
            }
        }

        return $this;
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

    public function getGmailWatchExpiry(): ?\DateTimeImmutable
    {
        return $this->gmailWatchExpiry;
    }

    public function setGmailWatchExpiry(?\DateTimeImmutable $gmailWatchExpiry): static
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

    public function isGmailWatchActive(): bool
    {
        if (null === $this->gmailWatchExpiry) {
            return false;
        }

        return $this->gmailWatchExpiry > new \DateTimeImmutable();
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

    public function getGmailLastPushAt(): ?\DateTimeImmutable
    {
        return $this->gmailLastPushAt;
    }

    public function setGmailLastPushAt(?\DateTimeImmutable $gmailLastPushAt): static
    {
        $this->gmailLastPushAt = $gmailLastPushAt;

        return $this;
    }

    public function getOauthLastRefreshAt(): ?\DateTimeImmutable
    {
        return $this->oauthLastRefreshAt;
    }

    public function setOauthLastRefreshAt(?\DateTimeImmutable $oauthLastRefreshAt): static
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

    public function isGmail(): bool
    {
        return AuthType::OAuth2->value === $this->authType
            && MailProvider::Google->value === $this->oauthProvider;
    }
}
