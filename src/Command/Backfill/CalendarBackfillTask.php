<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\UserRepository;
use App\Service\Calendar\CalendarProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives every existing user the calendars a new one now gets automatically:
 * a personal default, and one per mail account.
 *
 * Deliberately not done in the migration that created the tables. Provisioning
 * has to agree with what AccountCreator does when an account is added, and
 * writing it twice — once in PHP, once in SQL — is two definitions of "which
 * calendars should exist" that drift the first time either changes.
 *
 * Idempotent: CalendarProvisioner find-or-creates throughout, so re-running
 * after adding accounts fills only the gaps.
 */
final readonly class CalendarBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private UserRepository         $userRepository,
        private AccountRepository      $accountRepository,
        private CalendarProvisioner    $provisioner,
        private EntityManagerInterface $em,
    ) {
    }

    public function getName(): string
    {
        return 'calendars';
    }

    public function getDescription(): string
    {
        return 'Provision the default and per-account calendars for existing users.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->userRepository->count([]);

        if (0 === $total) {
            $io->success('No users to provision.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        // Keyset by id so each batch can flush and clear without holding a
        // server-side cursor — the shape CategoryBackfillTask uses.
        $lastId    = 0;
        $created   = 0;
        $processed = 0;

        while (true) {
            /** @var list<User> $users */
            $users = $this->userRepository->createQueryBuilder('usr')
                ->where('usr.id > :afterId')
                ->setParameter('afterId', $lastId)
                ->orderBy('usr.id', 'ASC')
                ->setMaxResults(self::BATCH_SIZE)
                ->getQuery()
                ->getResult();

            if (0 === count($users)) {
                break;
            }

            foreach ($users as $user) {
                $lastId = (int) $user->getId();

                $created += $this->provisioner->provision(
                    $user,
                    $this->accountRepository->findBy(['usr' => $user]),
                );

                $processed++;

                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('Provisioned calendars for %d user(s); %d created.', $processed, $created));

        return Command::SUCCESS;
    }
}
