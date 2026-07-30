<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\Mail\EmailAliasRepository;
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

    public static function normalize(string $address): string
    {
        return strtolower(trim($address));
    }

}
