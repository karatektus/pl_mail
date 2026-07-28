<?php

namespace App\Entity;

use App\Domain\Enum\MessageCategory;
use App\Domain\Enum\ThreadingMethod;
use App\Repository\MessageThreadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// One thread per provider conversation per account: Gmail's threadId and Graph's
// conversationId already express the grouping those backends show their own users,
// so the key is what we match on first and the constraint is what stops a
// concurrent batch from creating a second thread for the same conversation.
#[ORM\UniqueConstraint(name: 'uniq_message_thread_provider_key_account', columns: ['provider_thread_key', 'account_id'])]
// Serves the subject fallback lookup, which is always account-scoped.
#[ORM\Index(name: 'idx_message_thread_account_normalized_subject', columns: ['account_id', 'normalized_subject'])]
#[ORM\Entity(repositoryClass: MessageThreadRepository::class)]
class MessageThread
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messageThreads')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Account $account = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column]
    private ?int $messageCount = 0;

    #[ORM\Column]
    private ?int $unreadCount = 0;

    #[ORM\Column(nullable: true, enumType: MessageCategory::class)]
    private ?MessageCategory $category = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $starredAt = null;

    #[ORM\Column]
    private int $attachmentCount = 0;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'thread', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['receivedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $messages;

    /**
     * @var Collection<int, Label>
     */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(
        name: 'thread_label',
        joinColumns: [new ORM\JoinColumn(name: 'message_thread_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'label_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
    )]
    private Collection $labels;

    #[ORM\Column(enumType: ThreadingMethod::class)]
    private ?ThreadingMethod $threadingMethod = null;

    // TEXT, not VARCHAR: this is derived from `subject`, which is itself TEXT, so
    // any length cap here is a length cap that can reject a legitimate message.
    #[ORM\Column(type: Types::TEXT)]
    private ?string $normalizedSubject = null;

    /**
     * Gmail threadId / Graph conversationId. Null for IMAP, which has no such concept.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerThreadKey = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $snoozedUntil = null;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->labels = new ArrayCollection();
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeImmutable $lastMessageAt): static
    {
        $this->lastMessageAt = $lastMessageAt;

        return $this;
    }

    public function getMessageCount(): ?int
    {
        return $this->messageCount;
    }

    public function setMessageCount(int $messageCount): static
    {
        $this->messageCount = $messageCount;

        return $this;
    }

    public function getUnreadCount(): ?int
    {
        return $this->unreadCount;
    }

    public function setUnreadCount(int $unreadCount): static
    {
        $this->unreadCount = $unreadCount;

        return $this;
    }

    public function getCategory(): ?MessageCategory
    {
        return $this->category;
    }

    public function setCategory(?MessageCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getStarredAt(): ?\DateTimeImmutable
    {
        return $this->starredAt;
    }

    public function setStarredAt(?\DateTimeImmutable $starredAt): static
    {
        $this->starredAt = $starredAt;

        return $this;
    }

    public function getAttachmentCount(): int
    {
        return $this->attachmentCount;
    }

    public function setAttachmentCount(int $attachmentCount): static
    {
        $this->attachmentCount = $attachmentCount;

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
            $message->setThread($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getThread() === $this) {
                $message->setThread(null);
            }
        }

        return $this;
    }

    public function getThreadingMethod(): ?ThreadingMethod
    {
        return $this->threadingMethod;
    }

    public function setThreadingMethod(ThreadingMethod $threadingMethod): static
    {
        $this->threadingMethod = $threadingMethod;

        return $this;
    }

    public function getNormalizedSubject(): ?string
    {
        return $this->normalizedSubject;
    }

    public function setNormalizedSubject(string $normalizedSubject): static
    {
        $this->normalizedSubject = $normalizedSubject;

        return $this;
    }

    public function getProviderThreadKey(): ?string
    {
        return $this->providerThreadKey;
    }

    public function setProviderThreadKey(?string $providerThreadKey): static
    {
        $this->providerThreadKey = $providerThreadKey;

        return $this;
    }

    public function getSnoozedUntil(): ?\DateTimeImmutable
    {
        return $this->snoozedUntil;
    }

    public function setSnoozedUntil(?\DateTimeImmutable $snoozedUntil): static
    {
        $this->snoozedUntil = $snoozedUntil;
        return $this;
    }

    public function isSnoozed(): bool
    {
        return $this->snoozedUntil !== null && $this->snoozedUntil > new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, Label>
     */
    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(Label $label): static
    {
        if (false === $this->labels->contains($label)) {
            $this->labels->add($label);
        }

        return $this;
    }

    public function removeLabel(Label $label): static
    {
        $this->labels->removeElement($label);

        return $this;
    }

    public function hasLabel(Label $label): bool
    {
        return $this->labels->contains($label);
    }
}
