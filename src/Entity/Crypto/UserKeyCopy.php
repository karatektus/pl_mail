<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Domain\Enum\Crypto\UserKeyCopyKind;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\Crypto\UserKeyCopyRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A second wrapping of a user key, for the day the password is gone.
 *
 * The key wrapped under something other than the password: a recovery phrase
 * the person wrote down, or a secret one of their browsers keeps in its own
 * storage. Neither half is here — the phrase is on paper, the device secret
 * in IndexedDB — so the server holding these rows can open none of them.
 *
 * Kept per user key, not per user, because it is the key it opens: a copy of
 * a key that was later locked is exactly what reactivates that key.
 */
#[ORM\Entity(repositoryClass: UserKeyCopyRepository::class)]
#[ORM\Table(name: 'user_key_copy')]
#[ORM\Index(name: 'idx_user_key_copy_key', columns: ['user_key_id'])]
#[ORM\HasLifecycleCallbacks]
class UserKeyCopy
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_key_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) UserKey $userKey;

    #[ORM\Column(length: 16, enumType: UserKeyCopyKind::class)]
    public private(set) UserKeyCopyKind $kind;

    #[ORM\Column(type: 'text')]
    public private(set) string $wrapped;

    /**
     * What the person sees in the list of known devices — the browser and
     * system it was made on. Null for a phrase.
     */
    #[ORM\Column(length: 100, nullable: true)]
    public private(set) ?string $label = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastUsedAt = null;

    public function __construct(UserKey $userKey, UserKeyCopyKind $kind, string $wrapped, ?string $label = null)
    {
        $this->userKey = $userKey;
        $this->kind    = $kind;
        $this->wrapped = $wrapped;
        $this->label   = null === $label ? null : mb_substr(trim($label), 0, 100);
    }
}
