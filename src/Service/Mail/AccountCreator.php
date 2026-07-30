<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that has to happen when a password-authenticated mail account is
 * added, in one place.
 *
 * Extracted from AccountController::new so the setup wizard can add an account
 * without a second, slightly different version of this — the sort order, the
 * primary flag and the alias seeding are exactly the kind of detail that gets
 * left out of a copy and is not noticed until someone's first account is not
 * their primary one.
 */
final readonly class AccountCreator
{
    public function __construct(
        private AccountRepository $accounts,
        private EntityManagerInterface $entityManager,
        private AliasSeeder $aliasSeeder,
    ) {
    }

    /**
     * The defaults a new account form starts from: the ports and encryption
     * almost every provider uses.
     */
    public function blank(): Account
    {
        return new Account()
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls');
    }

    public function create(Account $account, User $user): void
    {
        $account
            ->setAuthType('password')
            ->setIsActive(true)
            ->setUsr($user);

        // Appended, then the whole list renumbered: sortOrder and isPrimary are
        // positional, so the first account added has to come out primary
        // without anything having to special-case "is this the first one".
        $ordered   = $this->accounts->findForUserOrdered($user);
        $ordered[] = $account;

        $this->resequence($ordered);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        // After the flush: the seeder reads the persisted account.
        $this->aliasSeeder->seed($account);
    }

    /**
     * @param iterable<Account> $orderedAccounts
     */
    public function resequence(iterable $orderedAccounts): void
    {
        $index = 0;

        foreach ($orderedAccounts as $account) {
            $account
                ->setSortOrder($index)
                ->setIsPrimary(0 === $index)
                ->setUpdatedAt(new DateTimeImmutable());

            ++$index;
        }
    }
}
