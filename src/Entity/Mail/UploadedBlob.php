<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Repository\Mail\UploadedBlobRepository;
use DateTimeImmutable;
use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A blob a client uploaded but has not yet attached to anything.
 *
 * RFC 8620 §6.1 lets a client upload bytes first and reference the resulting
 * blobId from a later /set call. Until that happens the bytes belong to nobody,
 * so they are tracked here with an account for ownership and a timestamp for
 * expiry — the spec explicitly allows the server to reclaim unreferenced
 * uploads, and without that this is an unbounded disk leak behind an
 * authenticated endpoint.
 *
 * The bytes live on disk like every other blob; only the metadata is here.
 */
#[ORM\Entity(repositoryClass: UploadedBlobRepository::class)]
#[ORM\Table(name: 'uploaded_blob')]
#[ORM\Index(name: 'idx_uploaded_blob_created', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class UploadedBlob
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Account $account;

    /** Path relative to the project root. */
    #[ORM\Column(length: 255)]
    public private(set) string $path;

    #[ORM\Column(length: 255)]
    public private(set) string $contentType;

    #[ORM\Column]
    public private(set) int $size;


    public function __construct(
        Account $account,
        string $path,
        string $contentType,
        int $size,
    ) {
        $this->account = $account;
        $this->path = $path;
        $this->contentType = $contentType;
        $this->size = $size;
        $this->createdAt = new DateTimeImmutable();
    }
}
