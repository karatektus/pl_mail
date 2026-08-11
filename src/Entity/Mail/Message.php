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
use App\Domain\Trait\TimestampableTrait;
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
// The reaper's only query: rows of one account carrying a vanish mark, oldest
// first. Without it, every poll of every folder sequentially scans the
// account's messages to find the handful that went missing.
#[ORM\Index(name: 'idx_message_vanished', columns: ['account_id', 'vanished_at'])]
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Message extends MessageModel
{
    use TimestampableTrait;

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

    /**
     * When a folder listing last failed to find this row where it says it is.
     *
     * Not a deletion, and deliberately not one. A UID missing from one listing
     * is evidence: the message may equally have been moved to a folder this
     * sync cycle has not reached yet, and the same absence is what both events
     * look like from the folder being left. So the syncer records the absence,
     * clears the row's address so SentCopyReconciler::claim() can re-match it
     * by Message-ID wherever it turns up, and waits.
     *
     * What converts it into a deletion is time plus coverage, never the single
     * listing: VanishedMessageReconciler::reap() removes a row only once every
     * sync-enabled folder in the account has been listed since this instant and
     * none of them produced it. Any listing that does produce it gives the row
     * an address again, and relocateTo() clears this back to null.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $vanishedAt = null;

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
     * When an accepted EmailSubmission is due to leave, and the marker that
     * says one exists at all.
     *
     * A submission has no table of its own — its id IS the Email id — so
     * everything `EmailSubmission/get` reports has to be reconstructible from
     * this row. Before this column there was nothing to reconstruct a *held*
     * submission from: the release time existed only in the create response,
     * and a client that lost it had no way to ask again, which forced every
     * client to keep its schedules device-local. get answered notFound for the
     * whole hold and then "final", so a scheduled send was invisible for the
     * hours it mattered most.
     *
     * Set for every accepted submission, not only for held ones. It is what
     * distinguishes "submitted, not gone yet" from "a draft nobody ever sent",
     * and those two must not share an answer — an immediate submission is
     * genuinely pending for as long as the worker takes.
     *
     * Deliberately NOT the same thing as sentAt, and named so the two cannot be
     * confused at a glance: this is when it may leave, sentAt is when it did.
     * The two differ by the queue delay even on an immediate send, and for a
     * hold they differ by up to thirty days.
     */
    #[ORM\Column(name: 'submission_send_at', nullable: true)]
    public ?DateTimeImmutable $submissionSendAt = null;

    /**
     * When a submission was cancelled, and the reason `undoStatus: "canceled"`
     * can be reported at all.
     *
     * `cancelled` above cannot answer this: it is a one-shot flag that
     * SendMessageHandler CLEARS when the envelope it belongs to comes due —
     * deliberately, because the web composer's send path does not clear it and
     * a leftover flag would swallow the next genuine send. So five minutes
     * after a cancelled hold fires, `cancelled` is false again and the fact
     * that anything was cancelled has been erased. This column is the durable
     * half, and it is only ever written by EmailSubmission/set.
     *
     * Cleared when the same draft is submitted again, so a re-submission is
     * pending rather than eternally canceled.
     */
    #[ORM\Column(name: 'submission_cancelled_at', nullable: true)]
    public ?DateTimeImmutable $submissionCancelledAt = null;

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

    /**
     * Put this row in another folder, forgetting the address it had in the old
     * one.
     *
     * Stays a method because the two properties are one fact. A UID is not an
     * identity, it is half of an address: it means something only together with
     * the folder that issued it, and IMAP issues a *new* one in the destination
     * of every move. So the instant the mailbox pointer moves, the old UID stops
     * describing anything, and the pair has to change together or not at all.
     *
     * Setting them apart is the whole trash-duplicate bug. Trashing re-pointed
     * the mailbox and kept the source UID, so the row claimed an address the
     * server would not confirm — while the destination's next sync met the real
     * UID as mail it had never seen and inserted a second row beside it. Every
     * move left one more ghost, which is why an account with 35 messages on the
     * server could hold 86 rows: not doubling, accumulating.
     *
     * A null UID is the honest state after a move whose result we have not seen
     * yet, and it is not a lossy one — it is exactly the state
     * SentCopyReconciler::claim() reconciles, by the Message-ID that survives
     * the move when the address does not. Pass $uid only when the server has
     * already said where the message landed.
     */
    public function relocateTo(?Mailbox $mailbox, ?int $uid = null): static
    {
        $this->mailbox = $mailbox;
        $this->imapUid = $uid;

        // A row that has an address again is not missing. Whatever listing
        // failed to produce it, this one did, and that is the answer that
        // counts — see $vanishedAt.
        if (null !== $uid) {
            $this->vanishedAt = null;
        }

        return $this;
    }
}
