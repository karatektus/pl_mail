<?php

namespace App\Entity\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Model\MessageModel;
use App\Entity\Label\Label;
use App\Repository\Mail\MessageRepository;
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
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Mailbox $mailbox = null;

    #[ORM\Column(nullable: true, enumType: MessageCategory::class)]
    public ?MessageCategory $category = null;

    #[ORM\Column(nullable: true)]
    public ?int $imapUid = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $messageId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $gmailId = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $graphId = null;

    /**
     * Gmail threadId / Graph conversationId, carried from the sync payload so the
     * threader can group without a second API call. Null for IMAP.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $providerThreadKey = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $gmailLabelIds = null;
    /**
     * @var array<string,string|list<string>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $headers = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $subject = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $fromAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $fromName = null;

    #[ORM\Column(nullable: true)]
    public ?array $toAddresses = null;

    #[ORM\Column(nullable: true)]
    public ?array $ccAddresses = null;

    #[ORM\Column(nullable: true)]
    public ?array $bccAddresses = null;

    #[ORM\Column]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    public ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $receivedAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $seenAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $starredAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $inReplyTo = [];

    #[ORM\Column(name: 'thread_references', type: 'json', nullable: true)]
    public ?array $references = [];

    #[ORM\Column(nullable: true)]
    public ?int $size = null;

    #[ORM\Column]
    public ?bool $hasAttachments = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $syncedAt = null;

    /**
     * Path (relative to the project root) of the original RFC822 bytes.
     *
     * Null means "not stored yet", not "unavailable": for Gmail and Graph the
     * bytes are fetched on first access and this is filled in then. Plain-IMAP
     * messages get it written during sync, where the raw message is already in
     * hand.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $rawPath = null;

    #[ORM\Column]
    public array $flags = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $bodyText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $bodyHtml = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $bodyHtmlSafe = null;

    /**
     * @var Collection<int, MessagePart>
     */
    #[ORM\OneToMany(targetEntity: MessagePart::class, mappedBy: 'message')]
    public private(set) Collection $messageParts;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?MessageThread $thread = null;

    /**
     * The only field here that is not public, and deliberately: Postgres writes
     * it, PHP never does, and no caller reads it either — the searches that use
     * it match on the column in SQL. It exists so the generated column is part
     * of the mapping and stays in the schema.
     */
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
    public bool $cancelled = false;

    /**
     * @var Collection<int, Label>
     */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(name: 'message_label')]
    public private(set) Collection $labels;

    public function __construct()
    {
        $this->messageParts = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Stays a method: it takes an argument and asks the label list about it, so
     * there is no single piece of state here to expose as a property.
     *
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

    public function addMessagePart(MessagePart $messagePart): static
    {
        if (!$this->messageParts->contains($messagePart)) {
            $this->messageParts->add($messagePart);
            $messagePart->message = $this;
        }

        return $this;
    }

    public function removeMessagePart(MessagePart $messagePart): static
    {
        if ($this->messageParts->removeElement($messagePart)) {
            if ($messagePart->message === $this) {
                $messagePart->message = null;
            }
        }

        return $this;
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

    /**
     * Stays a method: it takes an argument and asks the flag list about it, so
     * there is no single piece of state here to expose as a property.
     */
    public function hasFlag(MessageFlag $flag): bool
    {
        return in_array($flag->value, $this->flags, true);
    }
}
