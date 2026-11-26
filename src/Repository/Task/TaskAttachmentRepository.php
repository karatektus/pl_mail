<?php

declare(strict_types=1);

namespace App\Repository\Task;

use App\Entity\Task\TaskAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskAttachment>
 */
class TaskAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskAttachment::class);
    }

    /**
     * Every file name a row still points at, for the orphan sweep. Raw and flat,
     * because this is a list to compare against a directory, not a set of rows.
     *
     * @return list<string>
     */
    public function storedNames(): array
    {
        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT stored_name FROM task_attachment WHERE stored_name IS NOT NULL',
        );
    }
}
