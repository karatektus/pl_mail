<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Enum\Mail\SenderPictureStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\Mail\SenderPicture\SenderPictureRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One sender picture lookup and what it found (#639): Gravatar for an address,
 * a BIMI logo for a domain, a website icon for a registrable domain.
 *
 * **Shared by the whole install, not per user.** What gravatar.com answers for
 * an address is the same answer whoever asks, so it is asked once — and a
 * newsletter that reaches five people on a household server costs one lookup,
 * not five. Who may SEE a stored picture is decided when the page is drawn,
 * from that reader's own switches (SenderPictures), not here.
 *
 * **The row is also the queue's dedup.** The ingest step does not ask "is a
 * lookup already queued?" and then queue one — two batches of the same
 * newsletter would both see "no" — it claims the row in one statement
 * (SenderPictureRepository::claim()), and only the batch whose statement moved
 * the row to Pending dispatches the job.
 *
 * **Files are named by their contents** and live in SenderPictureStore; `file`
 * is that name. Fifty addresses at one company share its one BIMI logo, and
 * a Gravatar that has not changed since last month is the same file it was.
 */
#[ORM\Entity(repositoryClass: SenderPictureRepository::class)]
#[ORM\Table(name: 'sender_picture')]
// What claim()'s ON CONFLICT names, and the guard that makes a claim race-safe.
// Kind first: every lookup the page makes is "these subjects, of this kind".
#[ORM\UniqueConstraint(name: 'uniq_sender_picture_kind_subject', columns: ['kind', 'subject'])]
// For the scheduled refresh, which only re-asks about senders still writing.
#[ORM\Index(name: 'idx_sender_picture_last_seen_at', columns: ['last_seen_at'])]
#[ORM\HasLifecycleCallbacks]
class SenderPicture
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 16, enumType: SenderPictureKind::class)]
    public private(set) SenderPictureKind $kind;

    /** The address or domain, as SenderPictureSubject::of() made it. */
    #[ORM\Column(length: 320)]
    public private(set) string $subject;

    #[ORM\Column(length: 16, enumType: SenderPictureStatus::class)]
    public SenderPictureStatus $status = SenderPictureStatus::Pending;

    /** The stored file's name in SenderPictureStore; set only while Found. */
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $file = null;

    /** Who answered — see FetchedPicture::$origin. */
    #[ORM\Column(length: 512, nullable: true)]
    public ?string $origin = null;

    /** When the far end was last asked; null before the first answer. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $checkedAt = null;

    /**
     * When the job last took this row — written by claim(), read by it too: a
     * row Pending for over an hour was claimed by a job that never finished
     * (a worker killed mid-fetch) and may be claimed again.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $claimedAt = null;

    /**
     * When mail from this sender last arrived. The refresh asks again only
     * about senders seen in the last few months, and the rest are pruned.
     */
    #[ORM\Column]
    public DateTimeImmutable $lastSeenAt;

    public function __construct(SenderPictureKind $kind, string $subject)
    {
        $this->kind       = $kind;
        $this->subject    = $subject;
        $this->lastSeenAt = new DateTimeImmutable();
    }

    public function found(string $file, string $origin): void
    {
        $this->status    = SenderPictureStatus::Found;
        $this->file      = $file;
        $this->origin    = $origin;
        $this->checkedAt = new DateTimeImmutable();
    }

    public function missing(): void
    {
        $this->status    = SenderPictureStatus::Missing;
        $this->file      = null;
        $this->origin    = null;
        $this->checkedAt = new DateTimeImmutable();
    }

    /**
     * Nobody answered. A picture found before is KEPT: a website being down
     * for an afternoon is no reason to take a company's logo off every row,
     * and Failed is asked again the next day anyway.
     */
    public function failed(): void
    {
        $this->status    = null === $this->file ? SenderPictureStatus::Failed : SenderPictureStatus::Found;
        $this->checkedAt = new DateTimeImmutable();
    }
}
