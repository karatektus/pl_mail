<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Domain\Enum\Crypto\KeyPublicationState;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\Crypto\KeyPublicationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One address of one of the person's own keys, as a public key server last
 * said it stands there (phase 6g of #28, App\Service\Crypto\KeyPublishing).
 *
 * The server's word, kept: a row exists because the person published the
 * key there, and it is what Settings → Encryption shows beside the key —
 * "waiting for confirmation" until they click the link the server mailed
 * them — and what tells KeyPublishing which servers a revocation has to
 * reach when the key is marked compromised. Refreshed by asking the server
 * again; never guessed.
 *
 * Owned through the address key, which is the person's, and gone with it.
 */
#[ORM\Entity(repositoryClass: KeyPublicationRepository::class)]
#[ORM\Table(name: 'key_publication')]
// One state per address per server per key: publishing again updates it.
#[ORM\UniqueConstraint(name: 'uniq_key_publication', columns: ['address_key_id', 'host', 'email'])]
#[ORM\HasLifecycleCallbacks]
class KeyPublication
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'address_key_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) AddressKey $addressKey;

    /** The key server's host name, as APP_KEY_SERVERS spells it. 253: the longest DNS name. */
    #[ORM\Column(length: 253)]
    public private(set) string $host;

    #[ORM\Column(length: 255)]
    public private(set) string $email;

    #[ORM\Column(length: 16, enumType: KeyPublicationState::class)]
    public private(set) KeyPublicationState $state;

    /** When the server last said so. */
    #[ORM\Column]
    public private(set) DateTimeImmutable $checkedAt;

    public function __construct(AddressKey $addressKey, string $host, string $email, KeyPublicationState $state, DateTimeImmutable $checkedAt)
    {
        $this->addressKey = $addressKey;
        $this->host       = strtolower($host);
        $this->email      = strtolower(trim($email));
        $this->state      = $state;
        $this->checkedAt  = $checkedAt;
    }

    public function update(KeyPublicationState $state, DateTimeImmutable $checkedAt): void
    {
        $this->state     = $state;
        $this->checkedAt = $checkedAt;
    }
}
