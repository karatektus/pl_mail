<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Account;
use App\Repository\User\ApiTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * An app password: a long-lived, user-scoped credential a third-party mail
 * client uses against /jmap, in place of the short-lived JWT the first-party
 * app will use.
 *
 * Only a SHA-256 hash of the secret is stored, and the secret is shown exactly
 * once at creation. SHA-256 rather than a password hasher on purpose: the
 * secret is 32 bytes of CSPRNG output, so it has nothing to brute-force and
 * needs no key stretching — and unlike bcrypt, a plain digest can be looked up
 * by an indexed equality match instead of scanning every row on every request.
 *
 * Scope is the User, not an Account, matching the JMAP Session object: one
 * credential enumerates every connected mail account.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
#[ORM\UniqueConstraint(name: 'uniq_api_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_api_token_user', columns: ['usr_id'])]
#[ORM\HasLifecycleCallbacks]
class ApiToken
{
    use TimestampableTrait;

    /**
     * Marks our own credentials so the authenticator can tell an app password
     * from a JWT by inspection, and so a leaked one is greppable.
     */
    public const string PREFIX = 'plmail_';

    /** Bytes of entropy behind the secret. */
    private const int SECRET_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** What the user called it, e.g. "iPhone — Sterna". */
    #[ORM\Column(length: 100)]
    public string $name {
        set {
            $this->name = mb_substr(trim($value), 0, 100);
        }
    }

    #[ORM\Column(name: 'token_hash', length: 64)]
    public private(set) string $tokenHash;

    /**
     * First few characters of the secret, kept in clear so the list can show
     * which credential is which without being able to reconstruct it.
     */
    #[ORM\Column(length: 16)]
    public private(set) string $hint;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $revokedAt = null;

    public function __construct(User $usr, string $name)
    {
        $this->usr = $usr;
        $this->name = '' === trim($name) ? 'App password' : $name;
    }

    /**
     * Mint a new credential. Returns the entity plus the one and only copy of
     * the plaintext secret — it is never recoverable afterwards.
     *
     * @return array{token:self,secret:string}
     */
    public static function create(User $usr, string $name): array
    {
        $token = new self($usr, $name);
        $secret = self::PREFIX.bin2hex(random_bytes(self::SECRET_BYTES));

        $token->tokenHash = self::hash($secret);
        $token->hint = substr($secret, strlen(self::PREFIX), 6);

        return ['token' => $token, 'secret' => $secret];
    }

    /**
     * Rebuild a credential that already exists somewhere else, from its stored
     * hash rather than from a new secret.
     *
     * The counterpart of create(), and deliberately not a variant of it: this
     * one mints nothing. A config backup carries app passwords as the digests
     * they are stored as, because the plaintext was shown once at creation and
     * has not existed since — so a restore either puts the same digest back or
     * silently signs out every JMAP client the installation had. Reissuing
     * would be worse than dropping them: the operator would see the same number
     * of app passwords with the same names, and none of the phones would
     * connect.
     *
     * `revokedAt` travels with the row for the same reason: a revocation is a
     * decision, and one that came back undone would be a working credential
     * somebody had deliberately killed.
     *
     * @see \App\Service\Backup\ConfigBackupUserRestorer the only caller
     */
    public static function restore(
        User $usr,
        string $name,
        string $tokenHash,
        string $hint,
        ?DateTimeImmutable $lastUsedAt,
        ?DateTimeImmutable $revokedAt,
    ): self {
        $token = new self($usr, $name);

        $token->tokenHash  = $tokenHash;
        $token->hint       = $hint;
        $token->lastUsedAt = $lastUsedAt;
        $token->revokedAt  = $revokedAt;

        return $token;
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

    /**
     * Stays a method rather than becoming a property: this reads a timestamp
     * and answers a question about it. A predicate over non-boolean state is
     * an interpretation, not a plain read, and only a plain read is a property.
     */
    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    /**
     * How the secret is shown in listings once it can no longer be displayed.
     *
     * Virtual, so there is no column behind it — Doctrine refuses to map a
     * property whose hooks do not touch a backing store, which is exactly what
     * a derived value should be.
     */
    public string $masked {
        get => self::PREFIX.$this->hint.'…';
    }
}
