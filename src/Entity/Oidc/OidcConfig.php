<?php

declare(strict_types=1);

namespace App\Entity\Oidc;

use App\Domain\Trait\TimestampableTrait;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\Oidc\OidcConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The OpenID Connect provider people sign in to plMail through, as an
 * administrator entered it under Admin → SSO.
 *
 * **In the database, with the environment as the fallback** — the arrangement
 * MailProviderConfig has for the Google and Microsoft registrations, for the
 * reason FcmConfig gives: a setting that needs a container restart is one that
 * waits on whoever holds the compose file. App\Service\Oidc\OidcSettings says
 * which of the two is in force.
 *
 * The first version of single sign-on read the environment and nothing else,
 * on the argument that this setting decides who may sign in and so should not
 * be editable from inside a session it governs. That argument is real and the
 * decision went the other way knowingly: an administrator who can point the
 * install at a provider of their own can sign in as any account that has no
 * second factor. docs/internals/security-model.md states it for operators.
 *
 * **One row, and the schema says so**, exactly as FcmConfig does it: an
 * install has one sign-in provider, and a second row would make which one is in
 * force depend on insertion order.
 *
 * **A row counts only when it is whole.** Issuer, client id and secret come as
 * a set from one provider's console; a row holding two of the three is a setup
 * somebody got halfway through, and it must not shadow a working environment.
 * The form refuses to save one, and isComplete() is what every reader asks.
 *
 * The client secret is encrypted at rest through EncryptedStringType like every
 * other credential here, and never rendered back.
 */
#[ORM\Entity(repositoryClass: OidcConfigRepository::class)]
#[ORM\Table(name: 'oidc_config')]
// The singleton guarantee — see FcmConfig, which this copies.
#[ORM\UniqueConstraint(name: 'uniq_oidc_config_singleton', columns: ['singleton'])]
#[ORM\HasLifecycleCallbacks]
class OidcConfig
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Always 1. Never read; it exists so the unique index has a column to be
     * unique over.
     */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $issuer = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $clientId = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $clientSecret = null;

    /** The provider's name on the login button; null for the generic wording. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $buttonLabel = null;

    /** Space-separated; null asks for the default set. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $scopes = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $autoCreate = false;

    /**
     * On unless somebody turned it off: off, an address the provider never
     * verified can claim the account that holds it.
     */
    #[ORM\Column(options: ['default' => true])]
    public bool $requireVerifiedEmail = true;

    /**
     * Whether this row alone is enough to sign people in. A method rather than
     * a property for the reason MailProviderConfig gives: it weighs three
     * columns together.
     */
    public function isComplete(): bool
    {
        return '' !== trim((string) $this->issuer)
            && '' !== trim((string) $this->clientId)
            && true === $this->hasClientSecret();
    }

    /** Whether a secret is on file — never the secret itself. */
    public function hasClientSecret(): bool
    {
        return null !== $this->clientSecret && '' !== $this->clientSecret;
    }
}
