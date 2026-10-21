<?php

declare(strict_types=1);

namespace App\Repository\Note;

use App\Entity\Note\Note;
use App\Entity\Note\NoteShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteShare>
 */
class NoteShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteShare::class);
    }

    public function findByToken(string $token): ?NoteShare
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return list<NoteShare> */
    public function forNote(Note $note): array
    {
        /** @var list<NoteShare> */
        return $this->findBy(['note' => $note], ['id' => 'ASC']);
    }
}
