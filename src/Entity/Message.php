<?php

namespace App\Entity;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Model\MessageModel;
use App\Repository\MessageRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Remote-id uniqueness per account: one row per Gmail/Graph message. The
// batch handlers dedup in PHP before inserting, but that check is a read on
// stale data — this is the guard that actually holds when batches overlap
// across runs or retries. Provider id leads the column list so the indexes
// also serve the id-only lookups (findOneBy(['gmailId'|'graphId'])).
#[ORM\UniqueConstraint(name: 'uniq_message_gmail_id_account', columns: ['gmail_id', 'account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_message_graph_id_account', columns: ['graph_id', 'account_id'])]
// IMAP UIDs are unique within a mailbox, so this is the guard for the sync
// path. Mailbox leads: it matches how the syncer reads them back (all UIDs
// for one mailbox), and no query looks up a bare UID.
#[ORM\UniqueConstraint(name: 'uniq_message_mailbox_imap_uid', columns: ['mailbox_id', 'imap_uid'])]
#[ORM\Index(name: 'idx_message_search_vector', columns: ['search_vector'])]
// Threading reads both of these on every synced message. message_id is looked up
// by value across an account (parent lookup for References threading); the
// provider key is looked up account-scoped, so account trails it the same way
// the provider-id constraints above are ordered.
#[ORM\Index(name: 'idx_message_message_id', columns: ['message_id'])]
#[ORM\Index(name: 'idx_message_provider_thread_key_account', columns: ['provider_thread_key', 'account_id'])]
#[ORM\Entity(repositoryClass: MessageRepository::class)]
class Message extends MessageModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Mailbox $mailbox = null;

    #[ORM\Column(nullable: true, enumType: MessageCategory::class)]
    private ?MessageCategory $category = null;

    #[ORM\Column(nullable: true)]
    private ?int $imapUid = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gmailId = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $graphId = null;

    /**
     * Gmail threadId / Graph conversationId, carried from the sync payload so the
     * threader can group without a second API call. Null for IMAP.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerThreadKey = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $gmailLabelIds = null;
    /**
     * @var array<string,string|list<string>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $headers = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fromAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(nullable: true)]
    private ?array $toAddresses = null;

    #[ORM\Column(nullable: true)]
    private ?array $ccAddresses = null;

    #[ORM\Column(nullable: true)]
    private ?array $bccAddresses = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $receivedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $seenAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $starredAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $inReplyTo = [];

    #[ORM\Column(name: 'thread_references', type: 'json', nullable: true)]
    private ?array $references = [];

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column]
    private ?bool $hasAttachments = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $syncedAt = null;

    /**
     * Path (relative to the project root) of the original RFC822 bytes.
     *
     * Null means "not stored yet", not "unavailable": for Gmail and Graph the
     * bytes are fetched on first access and this is filled in then. Plain-IMAP
     * messages get it written during sync, where the raw message is already in
     * hand.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rawPath = null;

    #[ORM\Column]
    private array $flags = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyHtml = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyHtmlSafe = null;

    /**
     * @var Collection<int, MessagePart>
     */
    #[ORM\OneToMany(targetEntity: MessagePart::class, mappedBy: 'message')]
    private Collection $messageParts;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?MessageThread $thread = null;

    #[ORM\Column(
        name: 'search_vector',
        type: Types::TEXT,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(subject, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(from_name, '')), 'B') ||
        setweight(to_tsvector('english', coalesce(from_address, '')), 'B') ||
        setweight(to_tsvector('english', coalesce(body_text, '')), 'C')
    ) STORED"
    )]
    private ?string $searchVector = null;

    #[ORM\Column]
    private bool $cancelled = false;

    /**
     * @var Collection<int, Label>
     */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(name: 'message_label')]
    private Collection $labels;

    public function __construct()
    {
        $this->messageParts = new ArrayCollection();
        $this->setCreatedAt(new DateTimeImmutable());
        $this->setUpdatedAt(new DateTimeImmutable());
        $this->labels = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMailbox(): ?Mailbox
    {
        return $this->mailbox;
    }

    public function setMailbox(?Mailbox $mailbox): static
    {
        $this->mailbox = $mailbox;

        return $this;
    }

    public function getImapUid(): ?int
    {
        return $this->imapUid;
    }

    public function setImapUid(?int $imapUid): static
    {
        $this->imapUid = $imapUid;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getGmailId(): ?string
    {
        return $this->gmailId;
    }

    public function setGmailId(?string $gmailId): static
    {
        $this->gmailId = $gmailId;

        return $this;
    }

    public function getGraphId(): ?string
    {
        return $this->graphId;
    }

    public function setGraphId(?string $graphId): static
    {
        $this->graphId = $graphId;

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

    /**
     * @return list<string>|null
     */
    public function getGmailLabelIds(): ?array
    {
        return $this->gmailLabelIds;
    }

    /**
     * @param list<string>|null $gmailLabelIds
     */
    public function setGmailLabelIds(?array $gmailLabelIds): static
    {
        $this->gmailLabelIds = $gmailLabelIds;

        return $this;
    }

    /**
     * Convenience: returns true when this message has been given the STARRED
     * Gmail label (independent of the local \Flagged IMAP flag).
     */
    public function hasGmailLabel(string $label): bool
    {
        if (null === $this->gmailLabelIds) {
            return false;
        }

        return true === in_array($label, $this->gmailLabelIds, true);
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

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(?string $fromAddress): static
    {
        $this->fromAddress = $fromAddress;

        return $this;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): static
    {
        $this->fromName = $fromName;

        return $this;
    }

    public function getToAddresses(): ?array
    {
        return $this->toAddresses;
    }

    public function setToAddresses(?array $toAddresses): static
    {
        $this->toAddresses = $toAddresses;

        return $this;
    }

    public function getCcAddresses(): ?array
    {
        return $this->ccAddresses;
    }

    public function setCcAddresses(?array $ccAddresses): static
    {
        $this->ccAddresses = $ccAddresses;

        return $this;
    }

    public function getBccAddresses(): ?array
    {
        return $this->bccAddresses;
    }

    public function setBccAddresses(?array $bccAddresses): static
    {
        $this->bccAddresses = $bccAddresses;

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

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getReceivedAt(): ?DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(?DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getSeenAt(): ?DateTimeImmutable
    {
        return $this->seenAt;
    }

    public function setSeenAt(?DateTimeImmutable $seenAt): static
    {
        $this->seenAt = $seenAt;

        return $this;
    }

    public function getStarredAt(): ?DateTimeImmutable
    {
        return $this->starredAt;
    }

    public function setStarredAt(?DateTimeImmutable $starredAt): static
    {
        $this->starredAt = $starredAt;

        return $this;
    }

    public function getInReplyTo(): ?array
    {
        return $this->inReplyTo;
    }

    public function setInReplyTo(?array $inReplyTo): static
    {
        $this->inReplyTo = $inReplyTo;

        return $this;
    }

    public function getReferences(): ?array
    {
        return $this->references;
    }

    public function setReferences(?array $references): static
    {
        $this->references = $references;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function hasAttachments(): ?bool
    {
        return $this->hasAttachments;
    }

    public function setHasAttachments(?bool $hasAttachments): static
    {
        $this->hasAttachments = $hasAttachments;

        return $this;
    }

    public function getRawPath(): ?string
    {
        return $this->rawPath;
    }

    public function setRawPath(?string $rawPath): static
    {
        $this->rawPath = $rawPath;

        return $this;
    }

    public function getSyncedAt(): ?DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;

        return $this;
    }

    public function getFlags(): array
    {
        return $this->flags;
    }

    public function setFlags(array $flags): static
    {
        $this->flags = $flags;

        return $this;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(?string $bodyText): static
    {
        $this->bodyText = $bodyText;

        return $this;
    }

    public function getBodyHtml(): ?string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(?string $bodyHtml): static
    {
        $this->bodyHtml = $bodyHtml;

        return $this;
    }

    /**
     * @return Collection<int, MessagePart>
     */
    public function getMessageParts(): Collection
    {
        return $this->messageParts;
    }

    public function addMessagePart(MessagePart $messagePart): static
    {
        if (!$this->messageParts->contains($messagePart)) {
            $this->messageParts->add($messagePart);
            $messagePart->setMessage($this);
        }

        return $this;
    }

    public function removeMessagePart(MessagePart $messagePart): static
    {
        if ($this->messageParts->removeElement($messagePart)) {
            if ($messagePart->getMessage() === $this) {
                $messagePart->setMessage(null);
            }
        }

        return $this;
    }

    public function getThread(): ?MessageThread
    {
        return $this->thread;
    }

    public function setThread(?MessageThread $thread): static
    {
        $this->thread = $thread;

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function setCancelled(bool $cancelled): static
    {
        $this->cancelled = $cancelled;

        return $this;
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

    public function getBodyHtmlSafe(): ?string
    {
        return $this->bodyHtmlSafe;
    }

    public function setBodyHtmlSafe(?string $bodyHtmlSafe): static
    {
        $this->bodyHtmlSafe = $bodyHtmlSafe;

        return $this;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getAccount(): Account
    {
        return $this->account;
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

    /** @return array<string,string>|null */
    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    /** @param array<string,string>|null $headers */
    public function setHeaders(?array $headers): static
    {
        $this->headers = $headers;

        return $this;
    }

    public function hasFlag(MessageFlag $flag): bool
    {
        return in_array($flag->value, $this->flags, true);
    }
}
