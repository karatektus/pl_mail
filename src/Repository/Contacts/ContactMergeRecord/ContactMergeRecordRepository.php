<?php

declare(strict_types=1);

namespace App\Repository\Contacts\ContactMergeRecord;

use App\Entity\Contacts\ContactMergeRecord;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The record a merge of two contacts leaves behind, so it can be undone.
 *
 * @extends ServiceEntityRepository<ContactMergeRecord>
 */
class ContactMergeRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMergeRecord::class);
    }

    /**
     * A user's latest merges, newest first, for the duplicates page.
     *
     * @return list<ContactMergeRecord>
     */
    public function findRecentForUser(User $user, int $limit = 10): array
    {
        return $this->findBy(['usr' => $user], ['mergedAt' => 'DESC'], $limit);
    }
}
