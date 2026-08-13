<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Calendar\CalendarProvisioner;
use App\Twig\SenderAvatarExtension;
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
        $account = new Account();
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';

        return $account;
    }

    public function create(Account $account, User $user): void
    {
        $account->authType = 'password';
        $account->isActive = true;
        $account->usr = $user;

        $existing = $this->accounts->findForUserOrdered($user);

        // The colour is picked BEFORE the account joins the list, from the
        // colours already in use — it is the account's own from here on and
        // never moves again.
        $account->colorIndex = $this->freeColorIndex($existing);

        // Appended, then the whole list renumbered, so sortOrder stays the
        // dense 0-based position the list is drawn from.
        $ordered   = $existing;
        $ordered[] = $account;

        $this->resequence($ordered);

        // A first account is primary because there is nothing else it could be,
        // not because it landed at position 0.
        $this->ensurePrimary($ordered);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        // After the flush: the seeder reads the persisted account.
        $this->aliasSeeder->seed($account);

        // The default calendar, because that is where extraction files, and
        // the account's own beside it — so an event has a home before the first
        // sync rather than after someone notices, whichever of the two
        // Account::SETTING_CALENDAR_TARGET ends up naming.
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
     * Writes sequential sortOrder over the list, in the order given.
     *
     * DISPLAY ORDER ONLY. This used to set isPrimary on the row that came out
     * first, which meant dragging an account to the top of the settings list —
     * a gesture about tidying a list — silently changed the address every new
     * message would be sent from, with nothing on screen saying so. The two are
     * separate now: this moves rows, makePrimary chooses the sender.
     *
     * Caller is responsible for flushing.
     *
     * @param iterable<Account> $orderedAccounts
     */
    public function resequence(iterable $orderedAccounts): void
    {
        $index = 0;

        foreach ($orderedAccounts as $account) {
            $account->sortOrder = $index;

            ++$index;
        }
    }

    /**
     * Exactly one primary, no matter what just happened to the list.
     *
     * The invariant used to be free: position 0 always existed and was always
     * one row. Now that the flag is chosen rather than derived, the two ways it
     * can be lost have to be closed by hand — deleting the account that held it,
     * and adding the very first account, which has nothing to inherit from. A
     * list that has drifted (an import with two, a restore with none) is
     * repaired the way the old derivation would have answered it: the
     * lowest-ordered account wins, so nobody's From address moves.
     *
     * Caller is responsible for flushing.
     *
     * @param iterable<Account> $orderedAccounts in the user's display order
     */
    public function ensurePrimary(iterable $orderedAccounts): void
    {
        $accounts = is_array($orderedAccounts) ? $orderedAccounts : iterator_to_array($orderedAccounts);
        $primary  = null;

        foreach ($accounts as $account) {
            if (true === $account->isPrimary && null === $primary) {
                $primary = $account;

                continue;
            }

            $account->isPrimary = false;
        }

        if (null !== $primary || [] === $accounts) {
            return;
        }

        $accounts[array_key_first($accounts)]->isPrimary = true;
    }

    /**
     * Make one account the primary, and the only one.
     *
     * @param iterable<Account> $siblings every account of the same user
     */
    public function makePrimary(Account $account, iterable $siblings): void
    {
        foreach ($siblings as $sibling) {
            $sibling->isPrimary = false;
        }

        $account->isPrimary = true;
    }

    /**
     * The lowest palette slot nobody is using yet.
     *
     * The palette has eight entries and the point of the dot is telling
     * accounts apart, so "lowest free" keeps the first eight guaranteed
     * distinct — the property that made sortOrder attractive here in the first
     * place — without inheriting its habit of changing when the list moves.
     * Past eight accounts a colour is reused, which is what the modulo in
     * SenderAvatarExtension already did.
     *
     * @param iterable<Account> $existing
     */
    private function freeColorIndex(iterable $existing): int
    {
        $taken = [];

        foreach ($existing as $account) {
            $taken[$account->colorIndex] = true;
        }

        for ($index = 0; $index < SenderAvatarExtension::ACCOUNT_COLOURS; ++$index) {
            if (false === array_key_exists($index, $taken)) {
                return $index;
            }
        }

        return count($taken) % SenderAvatarExtension::ACCOUNT_COLOURS;
    }
}
