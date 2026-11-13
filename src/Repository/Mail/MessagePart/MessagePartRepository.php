<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessagePart;

use App\Entity\Mail\MessagePart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
     * The files a list row names as chips (#608): every part of these
     * messages that the reading pane lists as an attachment, in the order the
     * pane lists them.
     *
     * The same test as MessagePart::isListed() — not inline, not the sender's
     * own key — written as a WHERE so a page of fifty rows is one statement and
     * no part is hydrated. Four columns, because the chip draws a name and an
     * icon and needs nothing else; see AttachmentChip.
     *
     * @param list<int> $messageIds
     *
     * @return list<array{id: int, messageId: int, filename: ?string, contentType: ?string, sealed: bool}>
     */
    public function findListedForMessages(array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }

        /** @var list<array{id: int, messageId: int, filename: ?string, contentType: ?string, sealed: bool}> */
        return $this->createQueryBuilder('p')
            ->select('p.id', 'IDENTITY(p.message) AS messageId', 'p.filename', 'p.contentType', 'p.sealed')
            ->where('p.message IN (:messages)')
            ->andWhere('p.isInline IS NULL OR p.isInline = false')
            ->andWhere('p.senderKeyFingerprint IS NULL')
            ->setParameter('messages', $messageIds)
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getArrayResult();
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

    /**
     * Which of these part ids still exist, as a lookup set. For the thumbnail
     * sweep in PruneBlobsCommand, which names its files by part id.
     *
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    public function findExistingIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $existing = [];

        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM message_part WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        foreach ($result->iterateColumn() as $id) {
            $existing[(int) $id] = true;
        }

        return $existing;
    }
}
