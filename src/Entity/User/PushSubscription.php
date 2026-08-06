<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Domain\Enum\PushTransport;
use App\Entity\Mail\Account;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\User\PushSubscriptionRepository;
use DateTimeImmutable;
use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A JMAP PushSubscription (RFC 8620 §7.2): somewhere to send StateChange
 * objects when the user's data moves.
 *
 * Two transports, and the row says which it is. A Web Push subscription carries
 * an endpoint URL and the RFC 8291 keys the payload is encrypted to — whatever
 * the client hands us, a UnifiedPush distributor on Android, Apple's gateway
 * for an installed PWA on iOS, a browser push service on desktop; all of them
 * speak RFC 8030. An FCM subscription carries a registration token instead and
 * nothing else, because Google owns the endpoint, the encryption and the
 * delivery. They share the deviceClientId, the type filter, the expiry and the
 * verification handshake, and nothing below that.
 *
 * **The two shapes are mutually exclusive and the constructors enforce it.**
 * There is no public constructor: webPush() and fcm() are the only ways to make
 * one, so a row with both a URL and a token cannot be built by forgetting a
 * check. Doctrine hydrates by reflection and never calls either.
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
#[ORM\HasLifecycleCallbacks]
class PushSubscription
{
    use TimestampableTrait;

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

    /**
     * Which sender delivers to this row.
     *
     * Defaulted in the column as well as in PHP, because every subscription
     * that existed before FCM is a Web Push one and a migration that left the
     * column null would make the dispatcher choose a sender by accident.
     */
    #[ORM\Column(length: 16, enumType: PushTransport::class, options: ['default' => PushTransport::WebPush->value])]
    public private(set) PushTransport $transport = PushTransport::WebPush;

    /**
     * Where a Web Push payload is POSTed. Null on an FCM subscription — there
     * is no URL to hold, and an empty string would be a URL that fails at send
     * time rather than a row that says what it is.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $url = null;

    /**
     * The FCM registration token, null on a Web Push subscription.
     *
     * Encrypted at rest like every other credential this database holds. It is
     * not a password, but it is the whole address of one person's phone: anyone
     * holding it and this installation's Firebase project can wake that device,
     * and a token in a backup outlives the install that made it. The column
     * stays TEXT, so encrypting it costs no schema.
     */
    #[ORM\Column(name: 'fcm_token', type: EncryptedStringType::NAME, nullable: true)]
    public private(set) ?string $fcmToken = null;

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


    /** Cleared on success; used to retire endpoints that keep failing. */
    #[ORM\Column]
    public int $failureCount = 0;

    private function __construct(User $usr, string $deviceClientId, PushTransport $transport)
    {
        $this->usr = $usr;
        $this->deviceClientId = $deviceClientId;
        $this->transport = $transport;
        $this->createdAt = new DateTimeImmutable();
        $this->verificationCode = bin2hex(random_bytes(16));
    }

    public static function webPush(User $usr, string $deviceClientId, string $url): self
    {
        $subscription = new self($usr, $deviceClientId, PushTransport::WebPush);
        $subscription->url = $url;

        return $subscription;
    }

    public static function fcm(User $usr, string $deviceClientId, string $token): self
    {
        $subscription = new self($usr, $deviceClientId, PushTransport::Fcm);
        $subscription->fcmToken = $token;

        return $subscription;
    }

    /**
     * Point a Web Push subscription at a new endpoint, and start the handshake
     * over — a URL that has proved nothing must not inherit a verification
     * granted to a different one.
     *
     * Refuses on an FCM row rather than quietly writing a URL nothing reads:
     * the two shapes are exclusive, and a subscription holding both would be
     * delivered to by whichever sender the dispatcher asked first.
     */
    public function pointAt(string $url): void
    {
        if (PushTransport::WebPush !== $this->transport) {
            throw new \LogicException('Only a Web Push subscription has a URL; use rotateFcmToken() for an FCM one.');
        }

        $this->url = $url;
        $this->reissueVerification();
    }

    /**
     * Replace the FCM registration token, and start the handshake over.
     *
     * Rotation is routine on Android — Play services reissues a token after a
     * restore, a clear-data, or on its own schedule — so this is the ordinary
     * path rather than an exceptional one. It still re-verifies, for the reason
     * pointAt() does: the new token is a new device address, and carrying the
     * old verification across would mean a token nobody proved they can read
     * inherits delivery. The client is told by the PushVerification that
     * arrives at the new token immediately afterwards.
     */
    public function rotateFcmToken(string $token): void
    {
        if (PushTransport::Fcm !== $this->transport) {
            throw new \LogicException('Only an FCM subscription has a token; use pointAt() for a Web Push one.');
        }

        $this->fcmToken = $token;
        $this->reissueVerification();
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
     * A new address is a new endpoint, so the handshake starts over — whether
     * that address is a Web Push URL or an FCM token.
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
