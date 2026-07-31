<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Calendar>
 */
class CalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Calendar::class);
    }

    /**
     * Every calendar a user owns, in sidebar order.
     *
     * @return list<Calendar>
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The ones a view should actually read. Kept separate from findForUser()
     * because the settings page wants the hidden ones too.
     *
     * @return list<Calendar>
     */
    public function findVisibleForUser(UserInterface $user): array
    {
        return $this->findBy(
            ['usr' => $user, 'isVisible' => true],
            ['sortOrder' => 'ASC', 'id' => 'ASC'],
        );
    }

    public function findDefaultForUser(UserInterface $user): ?Calendar
    {
        return $this->findOneBy(['usr' => $user, 'isDefault' => true]);
    }

    /** The calendar mail from this account lands on. */
    public function findForAccount(Account $account): ?Calendar
    {
        return $this->findOneBy(['account' => $account, 'role' => CalendarRole::Account]);
    }

    public function findOneForUser(UserInterface $user, int $id): ?Calendar
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }
}
