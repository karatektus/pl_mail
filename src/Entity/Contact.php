<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContactRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_contact_user_email', columns: ['usr_id', 'email'])]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $usr = null;

    #[ORM\Column(length: 320)]
    private ?string $email = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column]
    private int $frequency = 1;

    #[ORM\Column]
    private ?DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $now = new DateTimeImmutable();
        $this->firstSeenAt = $now;
        $this->lastSeenAt  = $now;
        $this->createdAt   = $now;
        $this->updatedAt   = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsr(): ?User
    {
        return $this->usr;
    }

    public function setUsr(User $usr): static
    {
        $this->usr = $usr;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function getFrequency(): int
    {
        return $this->frequency;
    }

    public function setFrequency(int $frequency): static
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function getFirstSeenAt(): ?DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;
        return $this;
    }

    public function getLastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Returns initials (up to 2 chars) derived from display name or email.
     */
    public function getInitials(): string
    {
        $name = $this->displayName;

        if ($name !== null && $name !== '') {
            $parts = preg_split('/\s+/', trim($name));

            if (count($parts) >= 2) {
                return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
            }

            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($this->email ?? '?', 0, 1));
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->getDisplayName() ?? '', $this->getEmail());
    }


}
