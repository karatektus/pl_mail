<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Entity\Crypto\AddressKeyBinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AddressKeyBinding>
 */
class AddressKeyBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AddressKeyBinding::class);
    }
}
