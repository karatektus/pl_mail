<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\User\TrustedDeviceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A browser the user told plMail to stop asking for a second factor on.
 *
 * The row *is* the grant. scheb's stock trusted-device support is a signed JWT
 * cookie carrying nothing but a username and a version number: nothing to list,
 * and the only way to withdraw one is to bump the version, which logs out every
 * device the user owns. Holding the grants here instead buys two things that
 * matter for a mailbox — the user can see what is currently trusted, and
 * revoking one is immediate, because the check on every request is a lookup
 * against this table rather than a signature the server cannot take back.
 *
 * The cookie holds a plain 32-byte secret and only a SHA-256 of it is stored,
 * on the same reasoning as ApiToken: CSPRNG output has nothing to brute-force,
 * so a digest that can be looked up by an indexed equality match beats a
 * password hash that would force a scan of every row on every request.
 */
#[ORM\Entity(repositoryClass: TrustedDeviceRepository::class)]
#[ORM\Table(name: 'trusted_device')]
#[ORM\UniqueConstraint(name: 'uniq_trusted_device_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_trusted_device_user', columns: ['usr_id'])]
#[ORM\HasLifecycleCallbacks]
class TrustedDevice
{
    use TimestampableTrait;

    /** Bytes of entropy behind the cookie secret. */
    private const int SECRET_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    #[ORM\Column(name: 'token_hash', length: 64)]
    public private(set) string $tokenHash;

    /**
     * Which firewall issued it. A grant for the session-backed web login must
     * not be honoured by anything else that later trusts this table.
     */
    #[ORM\Column(length: 64)]
    public private(set) string $firewall;

    /**
     * What to call it in the list — "Firefox on macOS". Derived from the user
     * agent at creation and stored, rather than parsed on every render: the
     * point is to describe the device as it was when trusted, and a browser
     * that has since updated should not quietly relabel a row.
     */
    #[ORM\Column(length: 120)]
    public private(set) string $label;

    /**
     * The full user agent, kept beside the label so a user who does not
     * recognise a device has something concrete to go on.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $userAgent = null;

    /**
     * Last address seen using it, overwritten as it moves. A history would be
     * a tracking log, and this only has to answer "was that me?".
     */
    #[ORM\Column(length: 45, nullable: true)]
    public ?string $ipAddress = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    public private(set) DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $revokedAt = null;

    public function __construct(User $usr, string $firewall, string $label, DateTimeImmutable $expiresAt)
    {
        $this->usr = $usr;
        $this->firewall = $firewall;
        $this->label = '' === trim($label) ? 'Unknown device' : mb_substr(trim($label), 0, 120);
        $this->expiresAt = $expiresAt;
    }

    /**
     * Mint a grant. Returns the entity plus the one and only copy of the
     * cookie secret — it is never recoverable afterwards.
     *
     * @return array{device:self,secret:string}
     */
    public static function create(
        User $usr,
        string $firewall,
        string $label,
        DateTimeImmutable $expiresAt,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): array {
        $device = new self($usr, $firewall, $label, $expiresAt);
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        $device->tokenHash = self::hash($secret);
        $device->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 512);
        $device->ipAddress = $ipAddress;
        $device->lastUsedAt = new DateTimeImmutable();

        return ['device' => $device, 'secret' => $secret];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function revoke(): static
    {
        $this->revokedAt ??= new DateTimeImmutable();

        return $this;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    public function isActive(?DateTimeImmutable $now = null): bool
    {
        return null === $this->revokedAt && false === $this->isExpired($now);
    }

    /** Push the expiry out, for the extend_lifetime behaviour. */
    public function extendTo(DateTimeImmutable $expiresAt): static
    {
        if ($expiresAt > $this->expiresAt) {
            $this->expiresAt = $expiresAt;
        }

        return $this;
    }
}
