<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\PgpScheme;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A public OpenPGP key plMail knows for somebody a person writes with.
 *
 * One row per address and key, owned by one person: two people on the same
 * install who both write to anna@example.org each have their own row, because
 * what each of them has confirmed (pinned) or decided about a changed key is
 * theirs. There is no address book to hang it on — App\Entity\Mail\Contact is
 * only a cache for autocompletion and is rebuilt from mail — so the address is
 * the key.
 *
 * A key with three user IDs is three rows, one per address. The rules are per
 * address — which key is Current for anna@, whether bob@ has seen a change —
 * and a row per address keeps every one of them a plain column.
 *
 * The rules themselves (trust on first use, a changed key never replacing a
 * known one by itself) live in App\Service\Crypto\ContactKeys, the only writer.
 *
 * The per-recipient preferences sit on the row too, and are only meaningful
 * on the Current one: they travel to the new key when the person accepts a
 * change. Nothing reads them before compose can encrypt (phase 5 of issue #28).
 */
#[ORM\Entity(repositoryClass: ContactKeyRepository::class)]
#[ORM\Table(name: 'contact_key')]
// One row per person, address and key: seeing the same key again updates
// $lastSeenAt rather than adding a row. Person first, then address, so the
// index also serves the per-address lookup every signature check makes.
#[ORM\UniqueConstraint(name: 'uniq_contact_key_address_fingerprint', columns: ['usr_id', 'email', 'fingerprint'])]
// At most one key in use per address — the guard behind "a changed key never
// silently replaces the known one", which a read-then-write in PHP cannot be
// on its own when two workers collect keys from two messages at once. Partial,
// because any number of Changed keys may wait beside it.
#[ORM\UniqueConstraint(name: 'uniq_contact_key_current', columns: ['usr_id', 'email'], options: ['where' => "status = 'current'"])]
#[ORM\HasLifecycleCallbacks]
class ContactKey
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Lower-case, trimmed: the form every lookup compares against. */
    #[ORM\Column(length: 255)]
    public private(set) string $email;

    /** The primary key's fingerprint, lower-case hex. */
    #[ORM\Column(length: 64)]
    public private(set) string $fingerprint;

    /**
     * Armored, as gpg exported it after checking its self-signatures: what a
     * signature is checked against and, later, what mail is encrypted to.
     */
    #[ORM\Column(type: 'text')]
    public private(set) string $publicKey;

    #[ORM\Column(length: 16, enumType: ContactKeyOrigin::class)]
    public private(set) ContactKeyOrigin $origin;

    /**
     * The key server's host name for a key from one (ContactKeyOrigin
     * ::KeyServer) — "from the key server keys.openpgp.org" — and null for
     * every other origin. A column of its own rather than a case per server:
     * the servers differ in name, not in what their word is worth.
     */
    #[ORM\Column(length: 253, nullable: true)]
    public private(set) ?string $originHost = null;

    #[ORM\Column(length: 16, enumType: ContactKeyStatus::class)]
    public private(set) ContactKeyStatus $status;

    /**
     * The person compared the fingerprint with the contact and confirmed it.
     * The only thing that makes a contact's signature Valid rather than
     * Untrusted, and the reason a changed key can never replace this one
     * without the person saying so — which is true of every key, but for a
     * pinned one the warning is the loudest.
     */
    #[ORM\Column(options: ['default' => false])]
    public private(set) bool $pinned = false;

    /** When the key itself says it stops being valid; null for never. */
    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    public private(set) DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    public private(set) DateTimeImmutable $lastSeenAt;

    #[ORM\Column(length: 8, enumType: RecipientPolicy::class, options: ['default' => 'auto'])]
    public RecipientPolicy $encrypt = RecipientPolicy::Auto;

    #[ORM\Column(length: 8, enumType: RecipientPolicy::class, options: ['default' => 'auto'])]
    public RecipientPolicy $sign = RecipientPolicy::Auto;

    #[ORM\Column(length: 16, enumType: PgpScheme::class, options: ['default' => 'pgp-mime'])]
    public PgpScheme $scheme = PgpScheme::Mime;

    public function __construct(
        User $usr,
        string $email,
        string $fingerprint,
        string $publicKey,
        ContactKeyOrigin $origin,
        ContactKeyStatus $status,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $seenAt,
        ?string $originHost = null,
    ) {
        $this->usr         = $usr;
        $this->email       = strtolower(trim($email));
        $this->fingerprint = strtolower($fingerprint);
        $this->publicKey   = $publicKey;
        $this->origin      = $origin;
        $this->originHost  = ContactKeyOrigin::KeyServer === $origin ? $originHost : null;
        $this->status      = $status;
        $this->expiresAt   = $expiresAt;
        $this->firstSeenAt = $seenAt;
        $this->lastSeenAt  = $seenAt;
    }

    public function isCurrent(): bool
    {
        return ContactKeyStatus::Current === $this->status;
    }

    /**
     * Seen again, wherever from. A fresher copy of the same key — new
     * subkeys, a later expiry — replaces the stored one: same fingerprint,
     * same key, and gpg has checked the copy's self-signatures.
     */
    public function seenAgain(string $publicKey, ?DateTimeImmutable $expiresAt, DateTimeImmutable $at): void
    {
        $this->publicKey  = $publicKey;
        $this->expiresAt  = $expiresAt;
        $this->lastSeenAt = $at;
    }

    public function pin(): void
    {
        $this->pinned = true;
    }

    public function unpin(): void
    {
        $this->pinned = false;
    }

    /**
     * The person accepted this changed key in place of $previous (if there
     * still is one). Their preferences for the address come along; the
     * confirmation does not — it was about the other key.
     */
    public function acceptInPlaceOf(?self $previous): void
    {
        $this->status = ContactKeyStatus::Current;
        $this->pinned = false;

        if (null !== $previous) {
            $this->encrypt = $previous->encrypt;
            $this->sign    = $previous->sign;
            $this->scheme  = $previous->scheme;
        }
    }

    /** As a config backup carried it. */
    public function restoreState(bool $pinned, DateTimeImmutable $firstSeenAt, DateTimeImmutable $lastSeenAt): void
    {
        $this->pinned      = $pinned;
        $this->firstSeenAt = $firstSeenAt;
        $this->lastSeenAt  = $lastSeenAt;
    }
}
