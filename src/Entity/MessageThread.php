<?php

namespace App\Entity;

use App\Domain\Enum\MessageTab;
use App\Domain\Enum\ThreadingMethod;
use App\Repository\MessageThreadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\UX\Turbo\Attribute\Broadcast;

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

    #[ORM\Column(nullable: true, enumType: MessageTab::class)]
    private ?MessageTab $tab = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $starredAt = null;

    #[ORM\Column]
    private int $attachmentCount = 0;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'thread', orphanRemoval: true)]
    private Collection $messages;

    /**
     * @var Collection<int, Mailbox>
     */
    #[ORM\ManyToMany(targetEntity: Mailbox::class, inversedBy: 'messageThreads')]
    #[ORM\JoinTable(name: 'message_thread_mailbox')]
    private Collection $mailboxes;

    #[ORM\Column(enumType: ThreadingMethod::class)]
    private ?ThreadingMethod $threadingMethod = null;

    #[ORM\Column(length: 255)]
    private ?string $normalizedSubject = null;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->mailboxes = new ArrayCollection();
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

    public function getTab(): ?MessageTab
    {
        return $this->tab;
    }

    public function setTab(?MessageTab $tab): static
    {
        $this->tab = $tab;

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
        }

        return $this;
    }

    public function removeMailbox(Mailbox $mailbox): static
    {
        $this->mailboxes->removeElement($mailbox);

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
}
