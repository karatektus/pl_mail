<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\EventSuppression;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<EventSuppression>
 */
class EventSuppressionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventSuppression::class);
    }

    /** Hashes the key itself so no caller has to remember to. */
    public function isSuppressed(UserInterface $user, string $dedupKey): bool
    {
        return null !== $this->findOneBy([
            'usr'          => $user,
            'dedupKeyHash' => self::hash($dedupKey),
        ]);
    }

    public static function hash(string $dedupKey): string
    {
        return hash('sha256', $dedupKey);
    }
}
