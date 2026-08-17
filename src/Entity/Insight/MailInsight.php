<?php

declare(strict_types=1);

namespace App\Entity\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Insight\MailInsightRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fact read out of a mail that is worth showing before the mail is: a parcel
 * on its way, a flight with a time, a ticket, a pull request asking for eyes.
 *
 * The sibling of EventProposal with the opposite lifecycle. A proposal is a
 * GUESS held until a person answers it, and answering destroys the row; an
 * insight is a STATEMENT the extractor stands behind — nobody accepts a parcel
 * — so the row lives, updates itself as follow-up mails arrive, and retires by
 * time or by the user waving it away. That is why dismissal is a column here
 * rather than an EventSuppression: suppression exists to stop a guess being
 * re-offered, while a dismissed insight must still absorb its own follow-ups
 * (the DHL "it's here" mail upserts the same dedupe key) without coming back.
 *
 * $dedupeKey is what makes re-extraction an update instead of a duplicate.
 * The extractor derives it from the thing, not the mail — tracking number,
 * flight number + date, repo + issue number — so five mails about one parcel
 * are one card. Unique per ACCOUNT rather than per user: two accounts may
 * genuinely both receive tracking for the same parcel, and merging them would
 * make one account's dismissal eat the other's card.
 *
 * $payload is per-kind and deliberately schemaless at this layer; the
 * extractor that wrote it is named in $extractor, and the card template for
 * the kind is the reader. Versioned re-extraction (a better parser next
 * release) is the backfill's job, which is why nothing here forbids
 * overwriting a payload with a richer one.
 */
#[ORM\Entity(repositoryClass: MailInsightRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'mail_insight')]
#[ORM\UniqueConstraint(name: 'uniq_mail_insight_account_dedupe', columns: ['account_id', 'dedupe_key'])]
// The one sweep this table serves: "what is coming up / recent for this
// user's accounts". happens_at is nullable and the undated rows are reached
// by created_at, so both carry an index; kind rides along free in reads.
#[ORM\Index(name: 'idx_mail_insight_happens_at', columns: ['happens_at'])]
#[ORM\Index(name: 'idx_mail_insight_created_at', columns: ['created_at'])]
class MailInsight
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    /**
     * Where the card links to. SET NULL rather than CASCADE: the insight can
     * outlive the mail that announced it — a deleted shipping confirmation
     * does not un-ship the parcel — it merely loses its "open the mail" link.
     */
    #[ORM\ManyToOne(targetEntity: MessageThread::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?MessageThread $thread = null;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Message $message = null;

    #[ORM\Column(type: 'string', length: 32, enumType: InsightKind::class)]
    public InsightKind $kind;

    /** The card's one line: "DHL parcel from Zalando", "LH 1234 to JFK". */
    #[ORM\Column(type: 'string', length: 255)]
    public string $title = '';

    /** @var array<string, mixed> per-kind detail the card template reads */
    #[ORM\Column(type: Types::JSON)]
    public array $payload = [];

    /**
     * When the thing happens, if it has a when at all. A flight departs, a
     * ticket admits, a parcel estimates; a pull request just is — null.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $happensAt = null;

    /** Which extractor wrote this — see InsightExtractorInterface::key(). */
    #[ORM\Column(type: 'string', length: 32)]
    public string $extractor = '';

    /** Identity of the THING, not the mail — see the class doc. */
    #[ORM\Column(type: 'string', length: 160)]
    public string $dedupeKey = '';

    /**
     * Waved away by the user. The row stays so its dedupe key keeps absorbing
     * follow-up mails silently — deleting it would resurrect the card on the
     * carrier's next status update.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $dismissedAt = null;
}
