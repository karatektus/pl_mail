<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\AddressKeyStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Crypto\AddressKeyRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An OpenPGP key one or more of a person's addresses sign and decrypt with.
 *
 * A normal OpenPGP key — v4, Ed25519/Cv25519 when plMail made it, whatever it
 * was when imported — so that GnuPG, Thunderbird and Proton can read what
 * plMail exports. The private half is protected by a random passphrase, and
 * that passphrase ($token) is wrapped under the user key: changing the
 * password re-wraps the user key and leaves this row alone.
 *
 * Bound to addresses by AddressKeyBinding rather than to an Account or an
 * EmailAlias row. Aliases come and go with every sync, and a key outlives
 * them; an imported key with three user IDs serves three addresses, and an
 * address can have an old key it still decrypts with beside the new one it
 * signs with.
 */
#[ORM\Entity(repositoryClass: AddressKeyRepository::class)]
#[ORM\Table(name: 'address_key')]
#[ORM\UniqueConstraint(name: 'uniq_address_key_fingerprint', columns: ['usr_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_address_key_user_key', columns: ['user_key_id'])]
#[ORM\HasLifecycleCallbacks]
class AddressKey
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** The user key whose wrapping of $token opens this key. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_key_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) UserKey $userKey;

    /** Lower-case hex: 40 characters for a v4 key, 64 for a v6 one. */
    #[ORM\Column(length: 64)]
    public private(set) string $fingerprint;

    /** Armored. What contacts get, and what anyone may see. */
    #[ORM\Column(type: 'text')]
    public private(set) string $publicKey;

    /** Armored, and locked with the passphrase in $token. */
    #[ORM\Column(type: 'text')]
    public private(set) string $privateKey;

    /** The passphrase of $privateKey, wrapped under $userKey. */
    #[ORM\Column(type: 'text')]
    public private(set) string $token;

    #[ORM\Column(length: 16, enumType: AddressKeyOrigin::class)]
    public private(set) AddressKeyOrigin $origin;

    #[ORM\Column(length: 16, enumType: AddressKeyStatus::class)]
    public private(set) AddressKeyStatus $status = AddressKeyStatus::Active;

    /** Only ever set for an imported key; a generated one does not expire. */
    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $expiresAt = null;

    /**
     * Armored, and deliberately not encrypted: all it can do is revoke the key,
     * which is what it is kept for — the day the key and the password are both
     * lost, it is the one thing left that tells contacts to stop using it.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $revocationCertificate = null;

    /**
     * When the key was first used for anything anybody else could see: a
     * signature on a sent message, a copy of its public half handed out. Null
     * until then — and while it is, the key may simply be deleted, which is
     * what importing the key contacts already know does to the one plMail
     * generated a minute earlier. Nothing sets it before mail is sent with
     * keys (phase 5 of issue #28); the column is here so the rule is from day one.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $firstUsedAt = null;

    /**
     * The public key with its revocation, as the browser made it when the key
     * was marked compromised (phase 6g of #28). Kept so a key server the key
     * was published to is handed the revocation even if it could not be
     * reached at that moment: KeyPublishing uploads it again on the next
     * refresh until the server says "revoked". Public, like publicKey — no
     * private half is ever needed to send it.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $revokedPublicKey = null;

    /** @var Collection<int, AddressKeyBinding> */
    #[ORM\OneToMany(targetEntity: AddressKeyBinding::class, mappedBy: 'addressKey', cascade: ['persist'], orphanRemoval: true)]
    public private(set) Collection $bindings;

    public function __construct(
        User $usr,
        UserKey $userKey,
        string $fingerprint,
        string $publicKey,
        string $privateKey,
        string $token,
        AddressKeyOrigin $origin,
        ?DateTimeImmutable $expiresAt,
        ?string $revocationCertificate,
    ) {
        $this->usr                   = $usr;
        $this->userKey               = $userKey;
        $this->fingerprint           = strtolower($fingerprint);
        $this->publicKey             = $publicKey;
        $this->privateKey            = $privateKey;
        $this->token                 = $token;
        $this->origin                = $origin;
        $this->expiresAt             = $expiresAt;
        $this->revocationCertificate = $revocationCertificate;
        $this->bindings              = new ArrayCollection();
    }

    public function bind(string $email, bool $primary): AddressKeyBinding
    {
        $email = strtolower(trim($email));

        foreach ($this->bindings as $binding) {
            if ($email === $binding->email) {
                $binding->isPrimary = $primary;

                return $binding;
            }
        }

        $binding = new AddressKeyBinding($this, $email, $primary);
        $this->bindings->add($binding);

        return $binding;
    }

    /** @return list<string> */
    public function addresses(): array
    {
        return array_values(array_map(static fn (AddressKeyBinding $binding): string => $binding->email, $this->bindings->toArray()));
    }

    public function markObsolete(): void
    {
        if (AddressKeyStatus::Active === $this->status) {
            $this->status = AddressKeyStatus::Obsolete;
        }

        $this->dropPrimary();
    }

    /** As a config backup carried it. */
    public function restoreStatus(AddressKeyStatus $status): void
    {
        $this->status = $status;
    }

    public function markCompromised(): void
    {
        $this->status = AddressKeyStatus::Compromised;
        $this->dropPrimary();
    }

    /**
     * Moved under another user key: the one that replaced a locked key, once
     * a recovery copy opened the old one and the browser re-wrapped the token.
     */
    public function keepRevocation(string $revokedPublicKey): void
    {
        $this->revokedPublicKey = $revokedPublicKey;
    }

    public function rewrapToken(UserKey $userKey, string $token): void
    {
        $this->userKey = $userKey;
        $this->token   = $token;
    }

    private function dropPrimary(): void
    {
        foreach ($this->bindings as $binding) {
            $binding->isPrimary = false;
        }
    }
}
