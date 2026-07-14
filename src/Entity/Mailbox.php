<?php

namespace App\Entity;

use App\Domain\Enum\MailboxSpecialUse;
use App\Repository\MailboxRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailboxRepository::class)]
class Mailbox
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'mailboxes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Account $account = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 500)]
    private ?string $fullPath = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $delimiter = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?MailboxSpecialUse $specialUse = null;

    #[ORM\Column(nullable: true)]
    private ?int $uidValidity = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastSeenUid = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalMessages = null;

    #[ORM\Column(nullable: true)]
    private ?int $unreadMessages = null;

    #[ORM\Column]
    private ?bool $isSyncEnabled = null;

    #[ORM\Column]
    private bool $isIdleEnabled = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'mailbox', orphanRemoval: true)]
    private Collection $messages;

    /**
     * @var Collection<int, MessageThread>
     */
    #[ORM\ManyToMany(targetEntity: MessageThread::class, mappedBy: 'mailboxes')]
    private Collection $messageThreads;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->messageThreads = new ArrayCollection();
        $this->setCreatedAt(new \DateTimeImmutable());
        $this->setUpdatedAt(new \DateTimeImmutable());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getFullPath(): ?string
    {
        return $this->fullPath;
    }

    public function setFullPath(string $fullPath): static
    {
        $this->fullPath = $fullPath;

        return $this;
    }

    public function getDelimiter(): ?string
    {
        return $this->delimiter;
    }

    public function setDelimiter(?string $delimiter): static
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    public function getSpecialUse(): ?MailboxSpecialUse
    {
        return $this->specialUse;
    }

    public function setSpecialUse(?MailboxSpecialUse $specialUse): static
    {
        $this->specialUse = $specialUse;

        return $this;
    }

    public function getUidValidity(): ?int
    {
        return $this->uidValidity;
    }

    public function setUidValidity(?int $uidValidity): static
    {
        $this->uidValidity = $uidValidity;

        return $this;
    }

    public function getLastSeenUid(): ?int
    {
        return $this->lastSeenUid;
    }

    public function setLastSeenUid(?int $lastSeenUid): static
    {
        $this->lastSeenUid = $lastSeenUid;

        return $this;
    }

    public function getTotalMessages(): ?int
    {
        return $this->totalMessages;
    }

    public function setTotalMessages(?int $totalMessages): static
    {
        $this->totalMessages = $totalMessages;

        return $this;
    }

    public function getUnreadMessages(): ?int
    {
        return $this->unreadMessages;
    }

    public function setUnreadMessages(?int $unreadMessages): static
    {
        $this->unreadMessages = $unreadMessages;

        return $this;
    }

    public function isSyncEnabled(): ?bool
    {
        return $this->isSyncEnabled;
    }

    public function setIsSyncEnabled(bool $isSyncEnabled): static
    {
        $this->isSyncEnabled = $isSyncEnabled;

        return $this;
    }

    public function isIdleEnabled(): bool
    {
        return $this->isIdleEnabled;
    }

    public function setIsIdleEnabled(bool $isIdleEnabled): Mailbox
    {
        $this->isIdleEnabled = $isIdleEnabled;
        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;

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

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setMailbox($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getMailbox() === $this) {
                $message->setMailbox(null);
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
            $messageThread->addMailbox($this);
        }

        return $this;
    }

    public function removeMessageThread(MessageThread $messageThread): static
    {
        if ($this->messageThreads->removeElement($messageThread)) {
            $messageThread->removeMailbox($this);
        }

        return $this;
    }
}
