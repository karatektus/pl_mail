<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Domain\Enum\Crypto\UserKeyStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Crypto\UserKeyRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * The key at the top of one person's keyring, as the server holds it: wrapped,
 * and nothing it can open.
 *
 * 32 random bytes, made in the browser and wrapped there with AES-256-GCM
 * under the key the browser derives from the password (see
 * assets/crypto/keyring.js and App\Security\PasswordKdf). It wraps nothing but
 * the passphrases of the address keys below it, so a password change re-wraps
 * this one row and leaves every address key as it was.
 *
 * Symmetric, where the concept in issue #28 sketched an OpenPGP key: it is
 * never handed to anybody, never signs anything, and AES-GCM already refuses a
 * passphrase that was tampered with — an OpenPGP key here would be the same
 * job done with more moving parts. The address keys are the OpenPGP keys.
 *
 * More than one row per person only after the password was lost: the old key
 * is then locked rather than deleted, and a new one takes its place until a
 * recovery copy opens the old one again (see UserKeyCopy).
 */
#[ORM\Entity(repositoryClass: UserKeyRepository::class)]
#[ORM\Table(name: 'user_key')]
#[ORM\Index(name: 'idx_user_key_user', columns: ['usr_id'])]
#[ORM\HasLifecycleCallbacks]
class UserKey
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /**
     * The key, wrapped under the password-derived key — or, while locked, under
     * the one from a password nobody has any more. See App\Security\Crypto\Wrapped
     * for the shape.
     */
    #[ORM\Column(type: 'text')]
    public private(set) string $wrapped;

    #[ORM\Column(length: 16, enumType: UserKeyStatus::class)]
    public private(set) UserKeyStatus $status = UserKeyStatus::Active;

    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $lockedAt = null;

    public function __construct(User $usr, string $wrapped)
    {
        $this->usr     = $usr;
        $this->wrapped = $wrapped;
    }

    /**
     * A key exactly as a config backup carried it, lock and all.
     */
    public static function restore(User $usr, string $wrapped, UserKeyStatus $status, ?DateTimeImmutable $lockedAt): self
    {
        $key           = new self($usr, $wrapped);
        $key->status   = $status;
        $key->lockedAt = UserKeyStatus::Locked === $status ? $lockedAt : null;

        return $key;
    }

    /**
     * The password this key was wrapped with is gone. The wrapping stays as it
     * is — it is still the right bytes, just under a key nobody can derive —
     * and only a recovery copy opens it now.
     */
    public function lock(DateTimeImmutable $now): void
    {
        if (UserKeyStatus::Locked === $this->status) {
            return;
        }

        $this->status   = UserKeyStatus::Locked;
        $this->lockedAt = $now;
    }

    /**
     * Wrapped again under the current password: after a password change, or
     * when a recovery copy opened a locked key, which makes it active again.
     */
    public function rewrap(string $wrapped): void
    {
        $this->wrapped  = $wrapped;
        $this->status   = UserKeyStatus::Active;
        $this->lockedAt = null;
    }
}
