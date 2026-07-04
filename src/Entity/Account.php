<?php

namespace App\Entity;

use App\Domain\Model\AccountModel;
use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\UX\Turbo\Attribute\Broadcast;

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

    /**
     * @var Collection<int, Mailbox>
     */
    #[ORM\OneToMany(targetEntity: Mailbox::class, mappedBy: 'account', orphanRemoval: true)]
    private Collection $mailboxes;

    /**
     * @var Collection<int, MessageThread>
     */
    #[ORM\OneToMany(targetEntity: MessageThread::class, mappedBy: 'account', orphanRemoval: true)]
    private Collection $messageThreads;

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
}
