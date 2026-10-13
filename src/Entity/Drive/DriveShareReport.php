<?php

declare(strict_types=1);

namespace App\Entity\Drive;

use App\Repository\Drive\DriveShareReportRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Someone who was sent a Drive link flagged it — spam, malware,
 * something illegal. Admin → Drive lists the open reports with the actions to
 * take: revoke the link, switch off the owner's Drive, or dismiss.
 *
 * The reporter is anonymous by default; an email is optional so the admin can
 * follow up. Only the network address is kept otherwise, for rate limiting
 * and to spot one person filing many reports.
 */
#[ORM\Entity(repositoryClass: DriveShareReportRepository::class)]
#[ORM\Table(name: 'drive_share_report')]
#[ORM\Index(name: 'idx_drive_share_report_open', columns: ['resolved_at'])]
#[ORM\Index(name: 'idx_drive_share_report_share', columns: ['share_id'])]
#[ORM\Index(name: 'idx_drive_share_report_file', columns: ['file_id'])]
class DriveShareReport
{
    public const array REASONS = ['spam', 'malware', 'phishing', 'illegal', 'copyright', 'other'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'share_id', nullable: true, onDelete: 'SET NULL')]
    public ?DriveShare $share = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'file_id', nullable: true, onDelete: 'SET NULL')]
    public ?DriveFile $file = null;

    /** Kept even if the file goes, so the report still says what it was about. */
    #[ORM\Column(name: 'file_name', length: 255)]
    public string $fileName = '';

    #[ORM\Column(name: 'owner_id', nullable: true)]
    public ?int $ownerId = null;

    #[ORM\Column(length: 32)]
    public string $reason = 'other';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $details = null;

    #[ORM\Column(name: 'reporter_email', length: 255, nullable: true)]
    public ?string $reporterEmail = null;

    #[ORM\Column(name: 'reporter_ip', length: 45, nullable: true)]
    public ?string $reporterIp = null;

    #[ORM\Column(name: 'created_at')]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    public ?DateTimeImmutable $resolvedAt = null;

    /** revoked | disabled | dismissed */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $resolution = null;

    public static function file(DriveShare $share, string $reason, ?string $details, ?string $email, ?string $ip): self
    {
        $report = new self();
        $report->share = $share;
        $report->file = $share->file;
        $report->fileName = mb_substr($share->file->filename, 0, 255);
        $report->ownerId = $share->file->usr->id;
        $report->reason = \in_array($reason, self::REASONS, true) ? $reason : 'other';
        $report->details = null === $details || '' === trim($details) ? null : mb_substr(trim($details), 0, 2000);
        $report->reporterEmail = null === $email || '' === trim($email) ? null : mb_substr(trim($email), 0, 255);
        $report->reporterIp = null === $ip ? null : mb_substr($ip, 0, 45);
        $report->createdAt = new DateTimeImmutable();

        return $report;
    }

    public function resolve(string $resolution): void
    {
        $this->resolution = $resolution;
        $this->resolvedAt = new DateTimeImmutable();
    }
}
