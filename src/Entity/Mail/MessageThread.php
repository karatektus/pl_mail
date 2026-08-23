<?php

namespace App\Entity\Mail;

use App\Domain\Enum\Mail\LabelRole;
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

    /**
     * When this thread's row was first PUT IN FRONT OF the user in a list.
     *
     * The "new mail" marker, and deliberately not the same question as unread.
     * Unread asks whether the mail has been opened; this asks whether it has
     * been SHOWN. A conversation you scrolled past in the inbox and never
     * clicked is no longer new — you know it arrived — but it is still unread,
     * and both statements have to be sayable at once. Overloading seenAt or
     * unreadCount would collapse them into one.
     *
     * Null means new. A plain column rather than a per-user join table because
     * a thread hangs off an Account and an Account hangs off exactly one User,
     * so "was it shown" already has one answer per row; a join table would be a
     * second key for a relation that is one-to-one in practice.
     *
     * Written by MailController after the page has rendered — never before, or
     * the badge would retire in the same frame it was meant to appear in. Set
     * eagerly for locally-composed drafts (see MessageThreader::createThread):
     * mail you just wrote yourself has not "arrived".
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $listedAt = null;

    /**
     * How long mail stays "new" on age alone.
     *
     * A marker you can only clear by looking at every row is not a marker, it
     * is a debt — and mail that arrived yesterday is not news whether or not
     * anyone scrolled past it. So newness has a second, unconditional exit:
     * the window closes on its own.
     *
     * Spelled once, here, beside the column it qualifies. Every place that
     * decides newness — the row badge, the category tabs, the sidebar dots,
     * the counts endpoint and every repository query — goes through
     * newSince()/isNewAt() rather than repeating "24 hours" in SQL.
     */
    public const string NEW_WINDOW = 'PT24H';

    /**
     * The arrival time a thread has to beat to still count as new.
     *
     * A static so the repository can put the same boundary in a WHERE clause
     * that this entity applies in PHP; if the two ever disagreed, a sidebar dot
     * would promise new mail that the list below it declined to badge.
     */
    public static function newSince(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval(self::NEW_WINDOW));
    }

    /**
     * Is this conversation new AS OF $now — never shown, and recent enough to
     * still be worth announcing?
     *
     * All three parts are required. `listedAt IS NULL` alone was the original
     * rule and is what made the badge a chore; the window alone would re-badge
     * mail the user has already been shown for the rest of the day.
     *
     * And a conversation that has been READ is not new. That was the missing
     * third.
     *
     * Not because `listedAt` is per client — it is not, and that is worth being
     * precise about: it is a column on the conversation, so whichever surface
     * draws the row first speaks for all of them. The app reports a display
     * with `Thread/set { isNew: false }`, the browser POSTs to
     * /mail/threads/listed, and both write here. plMail on a phone and plMail
     * in a browser already agree.
     *
     * The gap is mail read somewhere that is NOT plMail. Gmail's own web
     * interface, a phone's built-in mail app, Thunderbird — any of them can set
     * \Seen, and the next sync brings the message in already read while no
     * plMail surface has ever drawn its row. It then arrives new AND read,
     * which is a row that is at once not-bold and badged "New" and tells the
     * reader nothing they can act on. Reported as "I often see new mail that's
     * already marked as read", and for anyone whose provider has a web client
     * it is the common case rather than an edge one.
     *
     * Reading it IS having seen it, which is the whole thing the marker is
     * trying to say. A conversation where one message was read and another has
     * since arrived still counts, because its unread count is above zero again.
     *
     * A thread with no lastMessageAt cannot be shown to have arrived inside the
     * window, so it is not new. That errs towards silence, which is the right
     * direction for a marker whose whole job is to be believed.
     */
    /**
     * New mail that answers something you sent.
     *
     * An escalation of newness rather than a second kind of it: everything
     * isNewAt() requires still applies — never shown, still unread, inside the
     * window — and this adds one thing on top. A stranger's first mail is news;
     * a reply to a mail you wrote is news you are waiting for, and the two
     * deserve to look different in a list of fifty.
     *
     * "Answers something you sent" is read off the conversation rather than off
     * headers, and that needs no new column: thread labels are the union of
     * their messages' labels, so a conversation carrying the Sent role is one
     * you have sent into. Combined with an unread message having arrived since
     * — which is what makes it new again — that IS a reply.
     *
     * The In-Reply-To chain would be the more literal reading and is worse
     * here. It is absent or wrong often enough that mail clients thread on
     * subject as a fallback (see ThreadingMethod), so a badge keyed to it would
     * be missing precisely on the mail from correspondents whose client is
     * careless — and the thread has already done that reconciliation once.
     */
    public function isAnswerAt(\DateTimeImmutable $now): bool
    {
        if (false === $this->isNewAt($now)) {
            return false;
        }

        foreach ($this->labels as $label) {
            if (LabelRole::Sent === $label->role) {
                return true;
            }
        }

        return false;
    }

    public function isNewAt(\DateTimeImmutable $now): bool
    {
        return null === $this->listedAt
            && $this->unreadCount > 0
            && null !== $this->lastMessageAt
            && $this->lastMessageAt >= self::newSince($now);
    }

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
