<?php

declare(strict_types=1);

namespace App\Entity\Drive;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Drive\DriveFolderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A folder in the user's personal Drive.
 *
 * Folders form a tree via parent. A null parent means the folder is at the
 * root. Files reference their containing folder (null = root).
 */
#[ORM\Entity(repositoryClass: DriveFolderRepository::class)]
#[ORM\Table(name: 'drive_folder')]
#[ORM\Index(name: 'idx_drive_folder_user', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_drive_folder_parent', columns: ['parent_id'])]
#[ORM\HasLifecycleCallbacks]
class DriveFolder
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    public private(set) ?self $parent = null;

    #[ORM\Column(length: 255)]
    public string $name {
        set {
            $this->name = mb_substr(trim($value), 0, 255);
        }
    }

    public static function create(User $user, string $name, ?self $parent = null): self
    {
        $folder = new self();
        $folder->usr = $user;
        $folder->name = $name;
        $folder->parent = $parent;

        return $folder;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function moveTo(?self $parent): void
    {
        $this->parent = $parent;
    }
}
