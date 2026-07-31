<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Calendar\CalendarProvisioner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

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
    /**
     * Where the outcome of the connection probe is kept. On the account rather
     * than in the wizard, because it is a fact about the account: it is just as
     * true on the settings page.
     */
    public const string SETTING_CONNECTION_ERROR = 'setup.connection_error';

    public function __construct(
        private AccountRepository $accounts,
        private EntityManagerInterface $entityManager,
        private AliasSeeder $aliasSeeder,
        private ConnectionTester $connectionTester,
        private CalendarProvisioner $calendarProvisioner,
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

        // The account's own calendar, so anything extracted from its mail has
        // a home before the first sync rather than after someone notices.
        $this->calendarProvisioner->defaultFor($user);
        $this->calendarProvisioner->forAccount($account);
        $this->entityManager->flush();

        $this->probe($account);
    }

    /**
     * Try the credentials that were just saved, and remember whether they work.
     *
     * An account that stores cleanly and cannot log in is the failure worth
     * catching here: nothing else tells the user until a sync silently fetches
     * nothing. A probe that throws is itself a failure to report, never a
     * reason to lose the account that was already saved.
     */
    public function probe(Account $account): void
    {
        try {
            $result = $this->connectionTester->test($account);

            $error = match (true) {
                false === $result->imapOk  => $result->imapMessage,
                false === $result->smtpOk  => $result->smtpMessage,
                default                    => null,
            };
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $account->setSetting(self::SETTING_CONNECTION_ERROR, $error);

        $this->entityManager->flush();
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
