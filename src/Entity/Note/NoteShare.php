<?php

declare(strict_types=1);

namespace App\Entity\Note;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\Note\NoteShareRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A link to one note that works without an account: /n/<token>.
 *
 * Same shape as a Drive share: 32 bytes of CSPRNG in the path are the gate,
 * an optional password is a second one (bcrypt, rate-limited), and an expiry
 * ends it. `canEdit` makes it a collaborator link — whoever holds it may change
 * the note's title, text and checklist, and nothing else about it.
 */
#[ORM\Entity(repositoryClass: NoteShareRepository::class)]
#[ORM\Table(name: 'note_share')]
#[ORM\UniqueConstraint(name: 'uniq_note_share_token', columns: ['token'])]
#[ORM\Index(name: 'idx_note_share_note', columns: ['note_id'])]
#[ORM\HasLifecycleCallbacks]
class NoteShare
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'note_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Note $note;

    #[ORM\Column(length: 64)]
    public private(set) string $token;

    #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
    public private(set) ?string $passwordHash = null;

    #[ORM\Column(name: 'expires_at', nullable: true)]
    public private(set) ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'can_edit', options: ['default' => false])]
    public private(set) bool $canEdit = false;

    #[ORM\Column(name: 'view_count', options: ['default' => 0])]
    public int $viewCount = 0;

    public static function create(Note $note, ?string $password, ?DateTimeImmutable $expiresAt, bool $canEdit): self
    {
        $share            = new self();
        $share->note      = $note;
        $share->token     = bin2hex(random_bytes(32));
        $share->expiresAt = $expiresAt;
        $share->canEdit   = $canEdit;

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
        return null === $this->passwordHash || password_verify($password ?? '', $this->passwordHash);
    }
}
