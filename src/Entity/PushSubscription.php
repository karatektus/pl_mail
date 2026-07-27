<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A JMAP PushSubscription (RFC 8620 §7.2): somewhere to POST StateChange
 * objects when the user's data moves.
 *
 * The endpoint is whatever the client hands us — a UnifiedPush distributor on
 * Android, Apple's Web Push gateway for an installed PWA on iOS, or a browser
 * push service on desktop. All of them speak RFC 8030, so one implementation
 * covers every platform.
 *
 * NOT the same thing as App\Service\Push\PushSubscriptionRegistry, which is
 * *inbound* — providers telling plMail that new mail arrived. This is outbound.
 *
 * Scoped to the User rather than an Account, matching the Session object: one
 * device subscribes once and hears about every connected mail account.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'jmap_push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_device', columns: ['usr_id', 'device_client_id'])]
#[ORM\Index(name: 'idx_push_user', columns: ['usr_id'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Client-chosen, stable per device+app — lets a reinstall replace itself. */
    #[ORM\Column(name: 'device_client_id', length: 255)]
    public private(set) string $deviceClientId;

    #[ORM\Column(type: 'text')]
    public string $url;

    /** P-256 ECDH public key of the client, base64url (RFC 8291). */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $p256dh = null;

    /** Client auth secret, base64url (RFC 8291). */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $auth = null;

    /**
     * Object types this device cares about; null means all of them.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $types = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $expires = null;

    /**
     * The code sent in the PushVerification POST. Until the client echoes it
     * back via PushSubscription/set, this subscription MUST NOT receive
     * StateChanges (RFC 8620 §7.2.2) — that handshake is what proves the URL
     * belongs to the client rather than being a way to aim our POSTs at a
     * third party.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public private(set) ?string $verificationCode = null;

    #[ORM\Column]
    public private(set) bool $verified = false;

    #[ORM\Column]
    public private(set) DateTimeImmutable $createdAt;

    /** Cleared on success; used to retire endpoints that keep failing. */
    #[ORM\Column]
    public int $failureCount = 0;

    public function __construct(User $usr, string $deviceClientId, string $url)
    {
        $this->usr = $usr;
        $this->deviceClientId = $deviceClientId;
        $this->url = $url;
        $this->createdAt = new DateTimeImmutable();
        $this->verificationCode = bin2hex(random_bytes(16));
    }

    public function verify(string $code): bool
    {
        if (null === $this->verificationCode) {
            return false;
        }

        if (false === hash_equals($this->verificationCode, $code)) {
            return false;
        }

        $this->verified = true;
        $this->verificationCode = null;

        return true;
    }

    /**
     * A new URL is a new endpoint, so the handshake starts over.
     */
    public function reissueVerification(): void
    {
        $this->verified = false;
        $this->verificationCode = bin2hex(random_bytes(16));
        $this->failureCount = 0;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if (null === $this->expires) {
            return false;
        }

        return $this->expires < ($now ?? new DateTimeImmutable());
    }

    public function wants(string $type): bool
    {
        if (null === $this->types) {
            return true;
        }

        return in_array($type, $this->types, true);
    }
}
