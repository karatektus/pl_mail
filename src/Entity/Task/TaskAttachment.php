<?php

declare(strict_types=1);

namespace App\Entity\Task;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Task\TaskAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file on a task: one kept here, or one another client linked to.
 *
 * **A file kept here is plMail's, whatever a server says.** It goes out over CalDAV
 * as an ATTACH link back to plMail, because 25 MB inlined as Base64 would travel
 * with every sync. If another client drops that link the row stays, and the next
 * write puts it back: many clients strip properties they do not understand, and
 * that should not cost anyone a file (#75).
 *
 * **A foreign link is the remote's.** It has no file here, is replaced whenever the
 * remote's copy changes, and goes back out untouched, because it stays in the
 * stored payload. Inline Base64 from another client stays there too and is not
 * listed: it is preserved, not read.
 */
#[ORM\Entity(repositoryClass: TaskAttachmentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'task_attachment')]
#[ORM\Index(name: 'idx_task_attachment_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_task_attachment_usr', columns: ['usr_id'])]
class TaskAttachment
{
    use TimestampableTrait;

    /** The most a file may be: what php.ini lets a request carry, said once more here. */
    public const int MAX_BYTES = 26_214_400;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Task $task;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    #[ORM\Column(type: Types::TEXT)]
    public string $filename = '';

    /** Told by the uploader or the remote, so never served as itself. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $contentType = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $size = null;

    /** Under the task store; null for a foreign link. See TaskAttachmentStorage. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $storedName = null;

    /** http or https only, or it is not stored: it is rendered as a link. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $externalUrl = null;

    public function isStoredHere(): bool
    {
        return null !== $this->storedName;
    }
}
