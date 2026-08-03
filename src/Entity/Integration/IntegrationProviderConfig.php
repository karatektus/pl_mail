<?php

declare(strict_types=1);

namespace App\Entity\Integration;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Trait\TimestampableTrait;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Application-level setup for one integration provider, owned by admins.
 *
 * This is the first admin-editable configuration in the database — the mail
 * OAuth credentials still live in env vars and stay there. Integrations differ
 * because the setup instructions and the form have to sit next to each other
 * for either to be useful, and because enabling a provider must not require
 * restarting a container.
 *
 * A row exists only once an admin has touched the provider; absence means
 * "not configured", which is the same thing as disabled. Provider is the
 * identity of the row, so there is exactly one config per service.
 */
#[ORM\Entity(repositoryClass: IntegrationProviderConfigRepository::class)]
#[ORM\Table(name: 'integration_provider_config')]
#[ORM\UniqueConstraint(name: 'uniq_integration_provider_config_provider', columns: ['provider'])]
#[ORM\HasLifecycleCallbacks]
class IntegrationProviderConfig
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 32, enumType: Provider::class)]
    public private(set) Provider $provider;

    #[ORM\Column]
    public bool $isEnabled = false;

    /**
     * Pins the server address for every user of this provider.
     *
     * Null leaves users free to enter their own, which is the point for
     * self-hosted services. Setting it locks the field in the connect form and
     * removes the SSRF surface entirely, so an admin running a single company
     * Nextcloud should set it.
     */
    #[ORM\Column(length: 512, nullable: true)]
    public ?string $baseUrl = null {
        set {
            $trimmed = null === $value ? null : rtrim(trim($value), '/');

            $this->baseUrl = '' === $trimmed ? null : $trimmed;
        }
    }

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $clientId = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $clientSecret = null;

    /** @var array<string,mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    public array $settings = [];

    public function __construct(Provider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Whether a user can actually connect to this today: an admin turned it on
     * and a driver exists. OAuth providers additionally need their client
     * credentials filled in, since the authorise redirect is meaningless
     * without them.
     *
     * Stays a method rather than becoming a property: the answer depends on
     * what the Provider enum says about itself, so it is an interpretation
     * across two objects rather than a read of this one.
     */
    public function isConnectable(): bool
    {
        if (false === $this->isEnabled || false === $this->provider->isImplemented()) {
            return false;
        }

        if (AuthKind::OAuth2 === $this->provider->authKind()) {
            return null !== $this->clientId && null !== $this->clientSecret;
        }

        return true;
    }

    /**
     * Never renders the secret itself — only whether one is on file.
     *
     * Stays a method: an existence check over a credential is not a read of it,
     * and turning it into a property would put the two one keystroke apart.
     */
    public function hasClientSecret(): bool
    {
        return null !== $this->clientSecret && '' !== $this->clientSecret;
    }
}
