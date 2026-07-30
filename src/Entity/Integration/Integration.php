<?php

declare(strict_types=1);

namespace App\Entity\Integration;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\Integration\IntegrationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's connection to one external file or photo service.
 *
 * Scoped to the User, like Label and ApiToken — a person's Nextcloud is theirs
 * whichever mail account they are reading. Unlike Label there is no
 * per-Account projection and so no binding table: nothing about an integration
 * varies by mail provider, so there is nothing for a second row to say.
 *
 * The same row serves both auth kinds. App-password providers fill in
 * baseUrl/username/secret; OAuth providers fill in the token trio and leave
 * the rest null. Splitting them into two entities would double the repository
 * and the UI to express a difference only the driver cares about.
 *
 * Every credential column is encrypted_string, and none of them is ever
 * rendered back — secretHint exists so the list can show which credential is
 * which, the same trick ApiToken uses.
 */
#[ORM\Entity(repositoryClass: IntegrationRepository::class)]
#[ORM\Table(name: 'integration')]
#[ORM\UniqueConstraint(name: 'uniq_integration_user_provider_name', columns: ['usr_id', 'provider', 'name'])]
#[ORM\Index(name: 'idx_integration_user', columns: ['usr_id'])]
#[ORM\HasLifecycleCallbacks]
class Integration
{
    use TimestampableTrait;

    /** How much of a credential stays readable in listings. */
    private const int HINT_LENGTH = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    #[ORM\Column(length: 32, enumType: Provider::class)]
    public private(set) Provider $provider;

    /** What the user called it, e.g. "Home Nextcloud". */
    #[ORM\Column(length: 100)]
    public string $name {
        set {
            $trimmed = trim($value);

            $this->name = mb_substr('' === $trimmed ? $this->provider->label() : $trimmed, 0, 100);
        }
    }

    /**
     * Server address for self-hosted services. Null for the SaaS providers,
     * and ignored in favour of the admin's value when one is pinned on the
     * provider config.
     */
    #[ORM\Column(length: 512, nullable: true)]
    public ?string $baseUrl = null {
        set {
            $trimmed = null === $value ? null : rtrim(trim($value), '/');

            $this->baseUrl = '' === $trimmed ? null : $trimmed;
        }
    }

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $username = null;

    /**
     * The app password or API key. Assigning it refreshes the hint, so the two
     * can never drift apart.
     */
    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $secret = null {
        set {
            $this->secret = $value;
            $this->secretHint = null === $value || '' === $value
                ? null
                : substr($value, -self::HINT_LENGTH);
        }
    }

    /** Last few characters of the secret, kept in clear for the listing. */
    #[ORM\Column(length: 16, nullable: true)]
    public private(set) ?string $secretHint = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $oauthAccessToken = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $oauthRefreshToken = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $oauthTokenExpiry = null;

    #[ORM\Column]
    public bool $isActive = true;

    /**
     * Free-form per-connection state, keys namespaced by concern —
     * "upload.folder" for the default save-to destination, "immich.albumId".
     * Mirrors the User::$settings and Account::$settings bags.
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    public array $settings = [];

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastCheckedAt = null;

    /**
     * Why the last probe failed, or null if it succeeded. Surfaced in the
     * settings list so a connection that has quietly stopped working — a
     * revoked app password, a moved server — says so before the user
     * discovers it mid-compose.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lastError = null;

    public function __construct(User $usr, Provider $provider, string $name = '')
    {
        $this->usr = $usr;
        $this->provider = $provider;
        $this->name = $name;
    }

    public function supports(Capability $capability): bool
    {
        return $this->isActive && $this->provider->supports($capability);
    }

    public function isHealthy(): bool
    {
        return null === $this->lastError;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings;
        $settings[$key] = $value;

        // Reassign rather than mutate in place: Doctrine compares the array by
        // value to decide whether the column is dirty, and an in-place write
        // to the same array instance is invisible to that check.
        $this->settings = $settings;
    }

    public function recordSuccess(): void
    {
        $this->lastCheckedAt = new DateTimeImmutable();
        $this->lastError = null;
    }

    public function recordFailure(string $reason): void
    {
        $this->lastCheckedAt = new DateTimeImmutable();
        $this->lastError = mb_substr($reason, 0, 500);
    }
}
