<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Repository\Crypto\AddressKeyBindingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One address an address key serves, and whether it is the one that address
 * signs and is encrypted to.
 *
 * By address string, lower-cased, and not by EmailAlias: see AddressKey on why
 * a key outlives the alias rows a sync creates and removes. At most one
 * primary binding per person and address is the keyring service's rule
 * (App\Service\Crypto\Keyring); PostgreSQL could hold it with a partial index,
 * and nothing else in this schema uses one.
 */
#[ORM\Entity(repositoryClass: AddressKeyBindingRepository::class)]
#[ORM\Table(name: 'address_key_binding')]
#[ORM\UniqueConstraint(name: 'uniq_address_key_binding', columns: ['address_key_id', 'email'])]
#[ORM\Index(name: 'idx_address_key_binding_email', columns: ['email'])]
class AddressKeyBinding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bindings')]
    #[ORM\JoinColumn(name: 'address_key_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) AddressKey $addressKey;

    #[ORM\Column(length: 255)]
    public private(set) string $email;

    #[ORM\Column(name: 'is_primary')]
    public bool $isPrimary;

    public function __construct(AddressKey $addressKey, string $email, bool $isPrimary)
    {
        $this->addressKey = $addressKey;
        $this->email      = strtolower(trim($email));
        $this->isPrimary  = $isPrimary;
    }
}
