<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One order, everywhere accounts are listed.
 *
 * The sidebar showed sven, uptime, pmd; settings showed pmd, sven, uptime; and
 * the drag handles that are supposed to arrange them lived on the settings page,
 * where the order they wrote was then sorted away by name on the next render.
 * So the control arranged nothing and the two lists disagreed.
 *
 * Two separate defects, and this covers both: settings has to read the arranged
 * order at all, and the sidebar's tiebreak has to match settings' on the
 * install where nobody has dragged anything — which is every install until
 * somebody does, and where sortOrder is 0 for every row.
 */
final class AccountOrderingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private AccountRepository $accounts;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->accounts   = $container->get(AccountRepository::class);

        $this->connection->beginTransaction();

        $this->user = new User();
        $this->user->email     = 'ordering-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Order';
        $this->user->nameLast  = 'Ing';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The reported install: three accounts, nobody has ever dragged anything,
     * so every sortOrder is the column default. Both lists must still agree,
     * and agree on something stable rather than on whatever the database
     * happened to return.
     */
    public function testAnUntouchedInstallOrdersBothListsTheSameWay(): void
    {
        $this->account('sven@example.test');
        $this->account('uptime@example.test');
        $this->account('pmd@example.test');

        self::assertSame(
            ['pmd@example.test', 'sven@example.test', 'uptime@example.test'],
            $this->emails($this->accounts->findActiveForUserOrdered($this->user)),
            'the sidebar falls back to name when nothing has been arranged',
        );

        self::assertSame(
            $this->emails($this->accounts->findActiveForUserOrdered($this->user)),
            $this->emails($this->accounts->findForUserOrdered($this->user)),
            'and settings and the sidebar must not disagree',
        );
    }

    /**
     * Once the user has arranged them, the arrangement wins over the name in
     * both lists — which is the whole point of the drag handles.
     */
    public function testAnArrangementIsHonouredByBothLists(): void
    {
        $this->account('sven@example.test', sortOrder: 1);
        $this->account('uptime@example.test', sortOrder: 2);
        $this->account('pmd@example.test', sortOrder: 3);

        $arranged = ['sven@example.test', 'uptime@example.test', 'pmd@example.test'];

        self::assertSame($arranged, $this->emails($this->accounts->findForUserOrdered($this->user)));
        self::assertSame($arranged, $this->emails($this->accounts->findActiveForUserOrdered($this->user)));
    }

    /**
     * The sidebar lists only what is switched on; settings lists everything,
     * because a disabled account still has to be manageable. The orders still
     * have to agree on the rows they share.
     */
    public function testTheSidebarListsOnlyActiveAccountsWithoutChangingTheOrder(): void
    {
        $this->account('sven@example.test', sortOrder: 1);
        $this->account('uptime@example.test', sortOrder: 2, isActive: false);
        $this->account('pmd@example.test', sortOrder: 3);

        self::assertSame(
            ['sven@example.test', 'pmd@example.test'],
            $this->emails($this->accounts->findActiveForUserOrdered($this->user)),
        );

        self::assertSame(
            ['sven@example.test', 'uptime@example.test', 'pmd@example.test'],
            $this->emails($this->accounts->findForUserOrdered($this->user)),
        );
    }

    /**
     * @param iterable<Account> $accounts
     *
     * @return list<string>
     */
    private function emails(iterable $accounts): array
    {
        $emails = [];

        foreach ($accounts as $account) {
            $emails[] = (string) $account->email;
        }

        return $emails;
    }

    private function account(string $email, int $sortOrder = 0, bool $isActive = true): Account
    {
        $account = new Account();
        $account->usr            = $this->user;
        $account->email          = $email;
        $account->username       = $email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = $isActive;
        $account->sortOrder      = $sortOrder;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
