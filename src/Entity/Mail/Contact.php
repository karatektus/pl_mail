<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Helper\AddressHelper;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_contact_user_email', columns: ['usr_id', 'email'])]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $usr = null;

    /**
     * Always a canonical, valid addr-spec. Doctrine hydrates through
     * `RawValuePropertyAccessor`, which skips hooks, so the normalisation below
     * runs on application writes only and never re-checks a stored row.
     *
     * The sync paths never come through here — they upsert in bulk via
     * `ContactRepository`, which normalises and drops invalid addresses on the
     * way in.
     */
    #[ORM\Column(length: 320)]
    public ?string $email = null {
        set (?string $value) {
            if (null === $value || '' === trim($value)) {
                throw new InvalidArgumentException('A contact needs an email address.');
            }

            if (false === AddressHelper::isValidEmail($value)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', $value));
            }

            $this->email = AddressHelper::email($value);
        }
    }

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $displayName = null;

    #[ORM\Column]
    public int $frequency = 1;

    #[ORM\Column(options: ['default' => false])]
    public bool $isCorrespondent = false;

    #[ORM\Column]
    public ?DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column]
    public ?DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    public ?DateTimeImmutable $updatedAt = null;

    /**
     * Initials for the avatar chip, at most two characters. Virtual and
     * unmapped: it is derived from the display name, or from the address when
     * there is none.
     */
    public string $initials {
        get {
            $name = $this->displayName;

            if (null !== $name && '' !== $name) {
                $parts = preg_split('/\s+/', trim($name));

                if (count($parts) >= 2) {
                    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
                }

                return mb_strtoupper(mb_substr($parts[0], 0, 2));
            }

            return mb_strtoupper(mb_substr($this->email ?? '?', 0, 1));
        }
    }

    public function __construct()
    {
        $now = new DateTimeImmutable();
        $this->firstSeenAt = $now;
        $this->lastSeenAt  = $now;
        $this->createdAt   = $now;
        $this->updatedAt   = $now;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->displayName ?? '', $this->email);
    }
}
