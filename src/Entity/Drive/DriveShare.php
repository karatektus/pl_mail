<?php

declare(strict_types=1);

namespace App\Entity\Drive;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\Drive\DriveShareRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A public share link for a Drive file.
 *
 * The token is a random string in the URL (/s/<token>). Optional password
 * (bcrypt-hashed) and expiry. Revoking deletes the row.
 */
#[ORM\Entity(repositoryClass: DriveShareRepository::class)]
#[ORM\Table(name: 'drive_share')]
#[ORM\UniqueConstraint(name: 'uniq_drive_share_token', columns: ['token'])]
#[ORM\Index(name: 'idx_drive_share_file', columns: ['file_id'])]
#[ORM\HasLifecycleCallbacks]
class DriveShare
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'file_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) DriveFile $file;

    /** The public token in the URL. */
    #[ORM\Column(length: 64, unique: true)]
    public private(set) string $token;

    /** Bcrypt hash of the password, or null for no password. */
    #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
    public ?string $passwordHash = null;

    #[ORM\Column(name: 'expires_at', nullable: true)]
    public ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'download_count')]
    public int $downloadCount = 0;

    public static function create(DriveFile $file, ?string $password, ?DateTimeImmutable $expiresAt): self
    {
        $share = new self();
        $share->file = $file;
        $share->token = bin2hex(random_bytes(32));
        $share->expiresAt = $expiresAt;

        if (null !== $password && '' !== $password) {
            $share->passwordHash = password_hash($password, PASSWORD_BCRYPT);
        }

        return $share;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < new DateTimeImmutable();
    }

    public function checkPassword(?string $password): bool
    {
        if (null === $this->passwordHash) {
            return true;
        }

        return password_verify($password ?? '', $this->passwordHash);
    }
}
