<?php

namespace App\Entity\Mail;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Repository\Mail\MailboxRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailboxRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Mailbox
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'mailboxes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Account $account = null;

    /** The display name, decoded to UTF-8 — "Entwürfe", not "Entw&APw-rfe". */
    #[ORM\Column(length: 255)]
    public ?string $name = null;

    /**
     * The folder's name on the wire, RAW, in modified UTF-7 (RFC 3501 §5.1.3)
     * — "INBOX.Entw&APw-rfe". Do not decode this, however wrong it looks in a
     * database viewer.
     *
     * It is not a display string, it is the folder's identity in the protocol:
     * MailboxSyncer indexes existing rows by it, and it is handed straight to
     * openFolder()/SELECT. Decode it and every account with a non-ASCII folder
     * stops being able to select that folder, and re-syncs as a duplicate.
     * ImapUtf7Helper exists for the places a person reads the name instead.
     */
    #[ORM\Column(length: 500)]
    public ?string $fullPath = null;

    #[ORM\Column(length: 5, nullable: true)]
    public ?string $delimiter = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?MailboxSpecialUse $specialUse = null;

    #[ORM\Column(nullable: true)]
    public ?int $uidValidity = null;

    #[ORM\Column(nullable: true)]
    public ?int $lastSeenUid = null;

    #[ORM\Column(nullable: true)]
    public ?int $totalMessages = null;

    #[ORM\Column(nullable: true)]
    public ?int $unreadMessages = null;

    #[ORM\Column]
    public ?bool $isSyncEnabled = null;

    #[ORM\Column]
    public bool $isIdleEnabled = false;

    /**
     * Whether the server lets this folder be opened at all.
     *
     * False for a folder it lists with \Noselect: a placeholder that only
     * groups other folders — Gmail's "[Gmail]", or the "Projects" above
     * "Projects/Alpha" on a server where a folder holds either mail or
     * folders. Such a folder has no mail to fetch and refuses SELECT.
     *
     * Written by MailboxSyncer on every folder sync, in both directions, so
     * it says what the server said last. It is a column and not something
     * read off the listing when needed because the place that needs it is the
     * folders dialog in Settings, which has no IMAP connection: without it a
     * placeholder was offered a sync switch that the next folder sync quietly
     * turned back off (see MailboxSyncer::update()).
     *
     * Not the same thing as isSyncEnabled, which is a choice. This is a fact,
     * and the reason that choice is not offered.
     */
    #[ORM\Column(options: ['default' => true])]
    public bool $isSelectable = true;

    /**
     * Which label this folder feeds is recorded on LabelBinding, alongside the
     * Gmail and Graph ids for the same label — one row per (label, account)
     * describes every provider. Mailbox reads through it and does not carry a
     * Label FK of its own.
     */
    #[ORM\OneToOne(targetEntity: LabelBinding::class, mappedBy: 'mailbox')]
    public ?LabelBinding $labelBinding = null;

    /**
     * The label this folder feeds, read through its binding. Bind a folder via
     * LabelResolver::bindMailbox().
     *
     * Virtual, so there is no column behind it — Doctrine refuses to map a
     * property whose hooks do not touch a backing store, which is exactly what
     * a derived value should be. Read-only for the same reason: the binding row
     * is the state, and this is only a view of it.
     */
    public ?Label $label {
        get => $this->labelBinding?->label;
    }

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $syncedAt = null;

    /**
     * When this folder's full UID set was last read off the server and compared
     * against the rows claiming to be in it.
     *
     * Separate from syncedAt because it happens on a slower clock. syncedAt is
     * every poll and asks only for UIDs above the high-water mark, which is
     * what makes incremental sync cheap and is also exactly why it can never
     * notice a message leaving. The sweep asks for all of them, so it runs on a
     * cadence instead — see VanishedMessageReconciler::SWEEP_INTERVAL.
     *
     * It is also the coverage half of the deletion rule. A row that vanished is
     * only erased once every folder's sweptAt is later than the instant it
     * vanished at, which is the difference between "no folder has it" and "the
     * folders we happen to have looked at do not have it".
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $sweptAt = null;

    /**
     * When the server's folder list first stopped naming this folder, and how
     * many listings in a row have left it out since.
     *
     * A folder is never removed on the strength of one LIST. Every message row
     * hangs off its mailbox with ON DELETE CASCADE, so removing the row is
     * removing the mail — and one LIST is equally consistent with a deletion, a
     * rename another client did, and a server that answered with half its
     * tree. MailboxSyncer marks the folder here, stops syncing it, and only
     * removes it once both of these say the absence has lasted: several
     * consecutive listings and a grace period. Seen again, both reset.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $missingSince = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $missingSyncs = 0;

    /**
     * The lowest UID the last sync could not store, and how many syncs in a
     * row it has failed.
     *
     * A failed UID holds lastSeenUid below it so the next sync asks again; this
     * is what stops one message that always fails from holding the folder
     * there for ever. See MessageSyncer::holdForRetry().
     */
    #[ORM\Column(nullable: true)]
    public ?int $failedUid = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $failedUidAttempts = 0;

    /**
     * How far down this folder's history has been brought in: every UID at or
     * above this one has been dealt with, and everything below it is still to
     * come.
     *
     *   null  nobody has looked yet. The next sync plans the import — see
     *         ImapImportPlan — and nothing is fetched until it has.
     *   0     there is nothing left below. The import is over, or the folder
     *         was read whole before imports worked this way.
     *   n     the import is under way and has reached n.
     *
     * WHY A SECOND MARKER BESIDE lastSeenUid
     *
     * lastSeenUid is a high-water mark and only moves up: it is what makes a
     * poll ask for new mail alone. A first import run through it reads a
     * folder from UID 1, oldest first, in one sitting — twenty thousand
     * messages before today's, with the newest last, and the whole account's
     * other mail waiting behind it (#42).
     *
     * So the two ends are separate. On first contact lastSeenUid is put at the
     * TOP of the folder, which makes new mail arrive at once and for ever
     * after, and this marker starts just above it and walks DOWN a page at a
     * time on a queue of its own (MailImporter). The newest mail is the first
     * page.
     */
    #[ORM\Column(nullable: true)]
    public ?int $importFloorUid = null;

    /**
     * How many messages this folder's import set out to bring in, and how many
     * of those are still to come. For the progress line, and allowed to be a
     * little wrong: mail is deleted and filed while an import runs.
     *
     * Null until the first page has asked the server what is there.
     */
    #[ORM\Column(nullable: true)]
    public ?int $importTotal = null;

    #[ORM\Column(nullable: true)]
    public ?int $importRemaining = null;

    /**
     * How many times in a row the page just above the floor has come back
     * with a message that would not store.
     *
     * The import's own counter, apart from failedUid above, and counted by
     * the page rather than by the message. The two ends of a folder are read
     * by different workers, and one ledger between them would have a message
     * failing at the top and one failing at the bottom take the count from
     * each other on every pass — the fault holdForRetry() already describes,
     * arrived at from a new direction. See ImapMailboxImporter.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $importPageAttempts = 0;

    /** Whether there is still history to bring in, or nobody has looked yet. */
    public function isImporting(): bool
    {
        return 0 !== $this->importFloorUid;
    }



    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'mailbox')]
    public private(set) Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->mailbox = $this;
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->mailbox === $this) {
                $message->mailbox = null;
            }
        }

        return $this;
    }
}
