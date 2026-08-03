<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\UploadedBlob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UploadedBlob>
 */
class UploadedBlobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UploadedBlob::class);
    }

    /**
     * Scoped to the account, so an upload belonging to another account can
     * never resolve.
     */
    public function findOneOwnedBy(int $id, Account $account): ?UploadedBlob
    {
        return $this->findOneBy(['id' => $id, 'account' => $account]);
    }

    /**
     * QueryBuilder for the `<` bound: findBy() compares a field to a value and
     * has no way to ask for everything before a moment.
     *
     * @return list<UploadedBlob>
     */
    public function findOlderThan(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every staged-upload path still pointed at by a row, as a lookup set —
     * the "keep" list for the blob sweep.
     *
     * One streamed sequential scan rather than a batched IN() per thousand
     * files: path is not indexed, and indexing it to serve a maintenance
     * command would tax every write to buy nothing the rest of the year.
     *
     * @return array<string, true>
     */
    public function findReferencedPaths(string $pathPrefix): array
    {
        $referenced = [];

        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT path FROM uploaded_blob WHERE path LIKE :prefix',
            ['prefix' => $pathPrefix.'/%'],
        );

        foreach ($result->iterateColumn() as $path) {
            $referenced[$path] = true;
        }

        return $referenced;
    }
}
