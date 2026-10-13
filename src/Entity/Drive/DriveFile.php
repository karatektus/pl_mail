<?php

declare(strict_types=1);

namespace App\Entity\Drive;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Drive\DriveFileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file in the user's personal Drive.
 *
 * Stored on disk by App\Service\Drive\DriveStorage under
 * <storage>/drive/<user_id>/<file_id>. The original filename is kept for
 * downloads; the storage name is the ID, so names cannot collide or traverse.
 */
#[ORM\Entity(repositoryClass: DriveFileRepository::class)]
#[ORM\Table(name: 'drive_file')]
#[ORM\Index(name: 'idx_drive_file_user', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_drive_file_folder', columns: ['folder_id'])]
#[ORM\HasLifecycleCallbacks]
class DriveFile
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Original filename, as uploaded. */
    #[ORM\Column(length: 255)]
    public string $filename {
        set {
            $this->filename = mb_substr(trim($value), 0, 255);
        }
    }

    #[ORM\Column(name: 'mime_type', length: 100)]
    public string $mimeType = 'application/octet-stream';

    #[ORM\Column(name: 'size_bytes', type: 'bigint')]
    public int $sizeBytes = 0;

    #[ORM\ManyToOne(targetEntity: DriveFolder::class)]
    #[ORM\JoinColumn(name: 'folder_id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?DriveFolder $folder = null;

    public static function create(User $user, string $filename, string $mimeType, int $size, ?DriveFolder $folder = null): self
    {
        $file = new self();
        $file->usr = $user;
        $file->filename = $filename;
        $file->mimeType = $mimeType;
        $file->sizeBytes = $size;
        $file->folder = $folder;

        return $file;
    }

    public function moveTo(?DriveFolder $folder): void
    {
        $this->folder = $folder;
    }

    public function rename(string $filename): void
    {
        $this->filename = $filename;
    }

    /** Lower-case extension without the dot, or '' when there is none. */
    public function extension(): string
    {
        $dot = strrpos($this->filename, '.');

        return false === $dot ? '' : strtolower(substr($this->filename, $dot + 1));
    }
}
