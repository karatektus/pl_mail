<?php

namespace App\Repository\Mail;

use App\Entity\Mail\MessagePart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessagePart>
 */
class MessagePartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessagePart::class);
    }

    /**
     * Every message that has at least one part, as ids.
     *
     * DISTINCT over a projection, which Doctrine's API has no form of: findBy()
     * returns whole parts, and a repair that walks messages would then load
     * every part of the mailbox to recover one integer per message.
     *
     * @return list<int>
     */
    public function findDistinctMessageIds(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT IDENTITY(p.message) AS id')
            ->getQuery()
            ->getArrayResult();

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Every attachment path still pointed at by a row, as a lookup set — the
     * "keep" list for the blob sweep.
     *
     * One streamed sequential scan rather than a batched IN() per thousand
     * files: storage_path is not indexed, and indexing it to serve a
     * maintenance command would tax every write to buy nothing the rest of the
     * year. The LIKE keeps provider-scheme values (gmail://, msgraph://) out of
     * the set, since those name no local file.
     *
     * @return array<string, true>
     */
    public function findReferencedStoragePaths(string $pathPrefix): array
    {
        $referenced = [];

        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT storage_path FROM message_part WHERE storage_path LIKE :prefix',
            ['prefix' => $pathPrefix.'/%'],
        );

        foreach ($result->iterateColumn() as $path) {
            $referenced[$path] = true;
        }

        return $referenced;
    }
}
