<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmailAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailAlias::class);
    }

    public function findOneByAccountAndAddress(Account $account, string $address): ?EmailAlias
    {
        return $this->findOneBy([
            'account' => $account,
            'address' => EmailAlias::normalize($address),
        ]);
    }
}
