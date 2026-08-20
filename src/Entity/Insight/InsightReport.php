<?php

declare(strict_types=1);

namespace App\Entity\Insight;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Insight\InsightReportRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A mail a user believes SHOULD have produced an insight, and did not.
 *
 * The feedback edge of the extraction pipeline, and the mirror image of
 * MailInsight: that entity records a fact an extractor found, this one records
 * a fact an extractor missed. Both exist because the extractors are
 * deterministic on purpose (InsightExtractorInterface argues that at length) —
 * a parser that never guesses is also a parser that stays silent on every shape
 * nobody has written down yet, and the only way to learn which shapes those are
 * is to let the person reading the mail say so.
 *
 * ── Why this row carries a copy of the mail ──────────────────────────────────
 * Every column from $fromAddress down is a SNAPSHOT taken when the report was
 * filed, not a view onto the message. A pointer would have been cheaper and is
 * wrong twice over: the report is worth reading months later, by which time the
 * mail has been archived, deleted, or vanished from the server (Message
 * ::$vanishedAt), and the whole point of the export is to hand somebody a
 * corpus of mail SHAPES they can write a parser against. A row that resolves to
 * a deleted message is a report that says only "something was missed once".
 *
 * So $message is a convenience link that may go null, and the snapshot beside
 * it is the payload. That is also why the report dialog states in plain words
 * that the mail's content travels to the administration: this row is a copy of
 * somebody's mail sitting in a table an admin can download, and a user who
 * files one has to know that is what the button does.
 *
 * $bodyText is truncated to MAX_BODY_CHARS. A parser is written against the
 * shape of the first screenful — the tracking number, the code, the "fällig
 * am" line — never against the eleventh quoted reply, and an untruncated
 * column would make the export a mail archive rather than a sample of one.
 *
 * ── Lifecycle ────────────────────────────────────────────────────────────────
 * Filed by the user, read by an admin, exported, then stamped $handledAt and
 * left in place. Stamped rather than deleted for the reason MailInsight
 * ::$dismissedAt gives for its own column: the row is what stops the same mail
 * being reported, counted and processed a second time, and deleting it would
 * make every export a fresh copy of work already done.
 */
#[ORM\Entity(repositoryClass: InsightReportRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'insight_report')]
// The two sweeps the admin panel makes: the list, newest first, and the
// "how many are waiting" count. handled_at carries both.
#[ORM\Index(name: 'idx_insight_report_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_insight_report_handled_at', columns: ['handled_at'])]
class InsightReport
{
    use TimestampableTrait;

    /**
     * How much body a report keeps — see the class doc on why this is a
     * sample and not an archive. Generous enough to hold a shipping mail
     * whole, small enough that a thousand reports are a few megabytes.
     */
    public const int MAX_BODY_CHARS = 16384;

    /** The user's own words are a hint, not an essay. */
    public const int MAX_NOTE_CHARS = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Who filed it, while that user still exists. SET NULL rather than
     * CASCADE, and nullable for the same reason the mail link is: a report
     * outlives the account that filed it, because what makes it useful is the
     * shape of the mail and not whose mail it was.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $reportedBy = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Account $account = null;

    /** The convenience link, and the first thing to go — see the class doc. */
    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Message $message = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $fromAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $fromName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $subject = null;

    /**
     * When the MAIL arrived, which is not when the report was filed
     * (createdAt). A parser that resolves a bare "24. Dezember" against the
     * wrong year is the bug ParcelExtractor::dateFrom() exists to avoid, and
     * it can only be reproduced from the mail's own instant.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $receivedAt = null;

    /** Plain text only, truncated to MAX_BODY_CHARS. Never the HTML part. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $bodyText = null;

    /**
     * What the user expected to see: "das ist eine Rechnung", "Sendungsnummer
     * steht ganz unten". The single most valuable column in the export, because
     * it names the fact a reader would otherwise have to guess at from the mail.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $note = null;

    /** Exported and dealt with. See the class doc on why this is not a delete. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $handledAt = null;
}
