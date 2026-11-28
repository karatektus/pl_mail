<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\Mail\EmailAlias\EmailAliasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One email address belonging to an Account's mailbox. An account has many;
 * they share one inbox. Modelled as a first-class row (not a JSON blob) so
 * ownership matching can look an account up by address in SQL if needed, and
 * so this generalises to Gmail send-as addresses later.
 */
#[ORM\Entity(repositoryClass: EmailAliasRepository::class)]
#[ORM\Table(name: 'email_alias')]
#[ORM\UniqueConstraint(name: 'uniq_email_alias_account_address', columns: ['account_id', 'address'])]
#[ORM\Index(name: 'idx_email_alias_address', columns: ['address'])]
#[ORM\HasLifecycleCallbacks]
class EmailAlias
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'aliases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) ?Account $account = null;

    #[ORM\Column(length: 320)]
    public private(set) string $address {
        set {
            $this->address = self::normalize($value);
        }
    }

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $displayName {
        set {
            $this->displayName = null !== $value && '' !== trim($value) ? trim($value) : null;
        }
    }

    /**
     * More names this address sends under, beside $displayName — "A", "a" and
     * "Arnaud" all writing from a@mail.com. Each is a line of its own in the
     * composer's From menu and an identity of its own over JMAP.
     *
     * A JSON list on the alias rather than rows of their own: a name has no
     * life apart from its address (no signature, no Bcc, no status — those
     * stay per address), and backup, deletion and the provider sync all take
     * it along with the alias for nothing.
     *
     * Each entry keeps a key of its own, never reused on this alias, because
     * the key is what the From token and the JMAP id are made of
     * (identityId()): renaming "Arnaud" to "Arnaud D." is an update of one
     * identity, not a new one, and a draft that picked it keeps it.
     *
     * @var list<array{key: int, name: string}>
     */
    #[ORM\Column(name: 'extra_names', type: 'json', options: ['default' => '[]'])]
    public private(set) array $extraNames = [];

    /**
     * The last key handed out on this alias. Kept apart from the list because
     * the list forgets: with only "the highest key there + 1", removing the
     * newest name would hand its key to the next one, and a draft saved under
     * the removed name would quietly send under the new one.
     */
    #[ORM\Column(name: 'extra_name_seq', options: ['default' => 0])]
    public private(set) int $extraNameSeq = 0;

    /** The longest extra name: the JMAP id stays well under its 255 octets whatever is typed. */
    public const int EXTRA_NAME_MAX_LENGTH = 100;

    /** What an extra identity's From token and JMAP id start with — never a digit, never "addr-". */
    public const string IDENTITY_PREFIX = 'n';

    #[ORM\Column(length: 20, enumType: EmailAliasStatus::class)]
    public EmailAliasStatus $status = EmailAliasStatus::Active;

    #[ORM\Column(length: 20, enumType: EmailAliasSource::class)]
    public private(set) EmailAliasSource $source = EmailAliasSource::Manual;

    public function __construct(
        Account          $account,
        string           $address,
        EmailAliasSource $source,
        EmailAliasStatus $status = EmailAliasStatus::Active,
        ?string          $displayName = null,
    ) {
        $this->account     = $account;
        $this->address     = $address;
        $this->source      = $source;
        $this->status      = $status;
        $this->displayName = $displayName;
    }

    /**
     * Add a name, and answer its key; null when it is empty once cleaned or
     * already one of this address's names (the default one included), so the
     * menu never shows the same line twice.
     */
    public function addExtraName(string $name, ?string $defaultName): ?int
    {
        $name = self::cleanName($name);

        if ('' === $name || true === $this->hasName($name, $defaultName)) {
            return null;
        }

        $key = ++$this->extraNameSeq;

        $this->extraNames = [...$this->extraNames, ['key' => $key, 'name' => $name]];

        return $key;
    }

    /** Rename one, keeping its key. False when the key is unknown or the name is taken or empty. */
    public function renameExtraName(int $key, string $name, ?string $defaultName): bool
    {
        $name = self::cleanName($name);

        if ('' === $name || null === $this->extraName($key)) {
            return false;
        }

        foreach ($this->extraNames as $entry) {
            if ($entry['key'] !== $key && $entry['name'] === $name) {
                return false;
            }
        }

        if (null !== $defaultName && $name === $defaultName) {
            return false;
        }

        $this->extraNames = array_map(
            static fn (array $entry): array => $entry['key'] === $key ? ['key' => $key, 'name' => $name] : $entry,
            $this->extraNames,
        );

        return true;
    }

    public function removeExtraName(int $key): bool
    {
        $kept = array_values(array_filter($this->extraNames, static fn (array $entry): bool => $entry['key'] !== $key));

        if (count($kept) === count($this->extraNames)) {
            return false;
        }

        $this->extraNames = $kept;

        return true;
    }

    public function extraName(int $key): ?string
    {
        foreach ($this->extraNames as $entry) {
            if ($entry['key'] === $key) {
                return $entry['name'];
            }
        }

        return null;
    }

    /**
     * Restore's way in: the names a backup carried, keys and all, cleaned the
     * way the form cleans them and with duplicates dropped.
     *
     * @param mixed $entries
     */
    public function restoreExtraNames(mixed $entries, int $seq = 0): void
    {
        $this->extraNames   = [];
        $this->extraNameSeq = max(0, $seq);

        if (false === is_array($entries)) {
            return;
        }

        $seen = [];

        foreach ($entries as $entry) {
            $key  = is_array($entry) ? ($entry['key'] ?? null) : null;
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? self::cleanName($entry['name']) : '';

            if (false === is_int($key) || $key < 1 || '' === $name || isset($seen[$key]) || in_array($name, array_column($this->extraNames, 'name'), true)) {
                continue;
            }

            $seen[$key]         = true;
            $this->extraNames[] = ['key' => $key, 'name' => $name];
            $this->extraNameSeq = max($this->extraNameSeq, $key);
        }
    }

    /**
     * The From token and JMAP id of one extra name: "n41-2" for key 2 on alias
     * 41. Built from ids only, never from the name, so it survives a rename,
     * and spelled in the alphabet RFC 8620 §1.2 allows an Id.
     */
    public function identityId(int $key): string
    {
        return self::IDENTITY_PREFIX . (int) $this->id . '-' . $key;
    }

    /**
     * The alias id and key an identity id names, or null when it is not one.
     * Says nothing about who owns the alias.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseIdentityId(mixed $id): ?array
    {
        if (false === is_string($id) || 1 !== preg_match('/^' . self::IDENTITY_PREFIX . '([1-9][0-9]*)-([1-9][0-9]*)$/', $id, $match)) {
            return null;
        }

        return [(int) $match[1], (int) $match[2]];
    }

    /**
     * A name as a header can carry it: control characters gone (a line break
     * would also land in the From menu and in JMAP), spaces trimmed, and cut
     * to EXTRA_NAME_MAX_LENGTH.
     */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        return mb_substr($name, 0, self::EXTRA_NAME_MAX_LENGTH);
    }

    private function hasName(string $name, ?string $defaultName): bool
    {
        return (null !== $defaultName && $name === $defaultName)
            || true === in_array($name, array_column($this->extraNames, 'name'), true);
    }

    public static function normalize(string $address): string
    {
        return strtolower(trim($address));
    }

}
