<?php

namespace App\Entity\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Repository\Mail\MessageThreadRepository;
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
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messageThreads')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Account $account = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $subject = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column]
    public ?int $messageCount = 0;

    #[ORM\Column]
    public ?int $unreadCount = 0;

    #[ORM\Column(nullable: true, enumType: MessageCategory::class)]
    public ?MessageCategory $category = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $starredAt = null;

    #[ORM\Column]
    public int $attachmentCount = 0;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'thread')]
    #[ORM\OrderBy(['receivedAt' => 'ASC', 'id' => 'ASC'])]
    public private(set) Collection $messages;

    /**
     * @var Collection<int, Label>
     */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(
        name: 'thread_label',
        joinColumns: [new ORM\JoinColumn(name: 'message_thread_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'label_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
    )]
    public private(set) Collection $labels;

    #[ORM\Column(enumType: ThreadingMethod::class)]
    public ?ThreadingMethod $threadingMethod = null;

    // TEXT, not VARCHAR: this is derived from `subject`, which is itself TEXT, so
    // any length cap here is a length cap that can reject a legitimate message.
    #[ORM\Column(type: Types::TEXT)]
    public ?string $normalizedSubject = null;

    /**
     * Gmail threadId / Graph conversationId. Null for IMAP, which has no such concept.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $providerThreadKey = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $snoozedUntil = null;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->labels = new ArrayCollection();
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->thread = $this;
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->thread === $this) {
                $message->thread = null;
            }
        }

        return $this;
    }

    /**
     * Stays a method rather than becoming a property: this reads a timestamp
     * and answers a question about it — and about the clock as well, so the
     * same stored value gives a different answer as the snooze expires. A
     * predicate over non-boolean state is an interpretation, not a plain read,
     * and only a plain read is a property.
     */
    public function isSnoozed(): bool
    {
        return $this->snoozedUntil !== null && $this->snoozedUntil > new \DateTimeImmutable();
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

    /**
     * Stays a method: it takes an argument and asks the collection about it, so
     * there is no single piece of state here to expose as a property.
     */
    public function hasLabel(Label $label): bool
    {
        return $this->labels->contains($label);
    }
}
