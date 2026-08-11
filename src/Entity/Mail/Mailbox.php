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
