<?php

declare(strict_types=1);

namespace App\Tests\Command\Setup;

use App\Entity\User\User;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The only way to mint an administrator after the first one.
 *
 * There is no web UI for this on purpose, so if the command writes the wrong
 * role list the install has no second route to a working admin. The assertions
 * are all on the stored `roles` column rather than the output, because that
 * column is what the firewall reads.
 */
final class PromoteUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:user:promote'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItGrantsTheAdminRole(): void
    {
        $user = MailFixtures::user($this->em, 'promote');

        $exit = $this->command->execute(['email' => (string) $user->email]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
        self::assertSame([User::ROLE_ADMIN], $this->storedRoles($user));
    }

    public function testItRevokesTheAdminRoleWhenAsked(): void
    {
        $user = MailFixtures::user($this->em, 'demote');
        $user->roles = [User::ROLE_ADMIN];
        $this->em->flush();

        $exit = $this->command->execute([
            'email'    => (string) $user->email,
            '--revoke' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotContains(User::ROLE_ADMIN, $user->getRoles());
        self::assertSame([], $this->storedRoles($user));
    }

    /**
     * Running it twice is the normal case — nobody remembers who is already an
     * admin — and a duplicated ROLE_ADMIN in the column is the kind of thing
     * that only shows up much later as a confusing audit listing.
     */
    public function testPromotingAnExistingAdminChangesNothing(): void
    {
        $user = MailFixtures::user($this->em, 'twice');

        $this->command->execute(['email' => (string) $user->email]);
        $this->command->execute(['email' => (string) $user->email]);

        self::assertSame([User::ROLE_ADMIN], $this->storedRoles($user));
    }

    /**
     * ROLE_USER is implied by getRoles(), so persisting it would make the
     * stored list disagree with the effective one for no benefit.
     */
    public function testTheImpliedUserRoleIsNeverWrittenBack(): void
    {
        $user = MailFixtures::user($this->em, 'implied');
        $user->roles = [User::ROLE_USER];
        $this->em->flush();

        $this->command->execute(['email' => (string) $user->email]);

        self::assertSame([User::ROLE_ADMIN], $this->storedRoles($user));
        self::assertContains(User::ROLE_USER, $user->getRoles());
    }

    public function testAnUnknownAddressIsRefused(): void
    {
        $exit = $this->command->execute(['email' => 'nobody-at-all@example.test']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No user found', $this->command->getDisplay());
    }

    /**
     * Read back out of the database rather than off the entity: the point of
     * the command is that the change survives the flush.
     *
     * @return list<string>
     */
    private function storedRoles(User $user): array
    {
        $raw = $this->connection->fetchOne(
            'SELECT roles FROM "user" WHERE id = ?',
            [$user->id],
        );

        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return array_values(array_map(strval(...), $decoded));
    }
}
