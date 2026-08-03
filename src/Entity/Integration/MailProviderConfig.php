<?php

declare(strict_types=1);

namespace App\Entity\Integration;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Trait\TimestampableTrait;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\Integration\MailProviderConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The OAuth app registration behind mail sign-in, for one provider.
 *
 * These credentials used to live only in env vars, which meant adding Gmail
 * support to a running deployment required editing a file and restarting a
 * container. They are the same kind of thing IntegrationProviderConfig already
 * holds — an admin-owned app registration — so they belong in the same place,
 * next to the setup instructions.
 *
 * Env vars still work and still win nothing: OAuthProviderFactory prefers a row
 * here and falls back to the environment, so an installation configured through
 * .env keeps running untouched and can migrate whenever it likes.
 *
 * Deliberately separate from IntegrationProviderConfig rather than sharing a
 * table with a wider enum: mail OAuth grants mailbox scopes and is consumed by
 * a different factory, and collapsing the two would mean one table whose rows
 * mean different things depending on which enum parsed them.
 */
#[ORM\Entity(repositoryClass: MailProviderConfigRepository::class)]
#[ORM\Table(name: 'mail_provider_config')]
#[ORM\UniqueConstraint(name: 'uniq_mail_provider_config_provider', columns: ['provider'])]
#[ORM\HasLifecycleCallbacks]
class MailProviderConfig
{
    use TimestampableTrait;

    /**
     * Settings key for the Azure tenant. Microsoft-only, so it lives in the bag
     * rather than as a column every provider would carry and one would use.
     */
    public const string TENANT_SETTING = 'tenant';

    /**
     * Gmail push settings. Google-only, so they share the bag with the tenant
     * rather than each becoming a column that one provider uses.
     *
     * The verification token is a shared secret Google echoes back on every
     * push, so it is encrypted alongside the client secret rather than kept in
     * the plain jsonb bag.
     */
    public const string PUBSUB_TOPIC_SETTING = 'pubsub.topic';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 32, enumType: MailProvider::class)]
    public private(set) MailProvider $provider;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $clientId = null;

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $clientSecret = null;

    /**
     * Shared secret Google includes in every Pub/Sub push, which the webhook
     * compares before trusting the notification. Encrypted for the same reason
     * the client secret is: it is a bearer credential in all but name.
     */
    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    public ?string $pushVerificationToken = null;

    /** @var array<string,mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    public array $settings = [];

    /**
     * Virtual, so there is no column behind it — the value lives in the
     * settings bag, and Doctrine refuses to map a property whose hooks do not
     * touch a backing store.
     */
    public ?string $pubsubTopic {
        get {
            $topic = $this->settings[self::PUBSUB_TOPIC_SETTING] ?? null;

            return is_string($topic) && '' !== $topic ? $topic : null;
        }
        set {
            $this->setSetting(self::PUBSUB_TOPIC_SETTING, $value);
        }
    }

    /** Virtual for the same reason as $pubsubTopic. */
    public ?string $tenant {
        get {
            $tenant = $this->settings[self::TENANT_SETTING] ?? null;

            return is_string($tenant) && '' !== $tenant ? $tenant : null;
        }
        set {
            $this->setSetting(self::TENANT_SETTING, $value);
        }
    }

    public function __construct(MailProvider $provider)
    {
        $this->provider = $provider;
    }

    /** Whether this row alone is enough to run the flow. */
    public function isComplete(): bool
    {
        return null !== $this->clientId && '' !== $this->clientId
            && null !== $this->clientSecret && '' !== $this->clientSecret;
    }

    /** Never renders the secret itself — only whether one is on file. */
    public function hasClientSecret(): bool
    {
        return null !== $this->clientSecret && '' !== $this->clientSecret;
    }

    /**
     * An absent key rather than an empty string, so "not configured" is one
     * state and not two.
     */
    private function setSetting(string $key, ?string $value): void
    {
        $settings = $this->settings;

        if (null === $value || '' === trim($value)) {
            unset($settings[$key]);
        } else {
            $settings[$key] = trim($value);
        }

        // Reassigned rather than mutated: Doctrine compares the array by value
        // to decide whether the column is dirty.
        $this->settings = $settings;
    }
}
