<?php

declare(strict_types=1);

namespace App\Tests\Command\Setup;

use App\Entity\User\User;
use App\Tests\Command\MailFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The only way an existing account gets a new password.
 *
 * There is no web UI for this and there is no forgotten-password mail flow, by
 * decision — so if this command is wrong, an install has nothing between a
 * forgotten password and hand-written SQL. The assertions go through the
 * password hasher rather than comparing strings, because "a hash was written"
 * is also what writing the wrong hash looks like.
 */
final class SetPasswordCommandTest extends KernelTestCase
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
            new Application(self::$kernel)->find('app:user:password'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItSetsAPasswordTheUserCanSignInWith(): void
    {
        $user = MailFixtures::user($this->em, 'pwd');

        $exit = $this->command->execute([
            'email'      => (string) $user->email,
            '--password' => 'a-long-enough-password',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($this->hasher()->isPasswordValid($user, 'a-long-enough-password'));
    }

    /** Typed rather than passed, which is the way an operator actually runs it. */
    public function testItAsksTwiceAndAcceptsAMatchingPair(): void
    {
        $user = MailFixtures::user($this->em, 'pwd-typed');

        $this->command->setInputs(['a-long-enough-password', 'a-long-enough-password']);
        $exit = $this->command->execute(['email' => (string) $user->email]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($this->hasher()->isPasswordValid($user, 'a-long-enough-password'));
    }

    /**
     * A typo does not fail on its own — it sets a password nobody knows, for
     * somebody who is already locked out. Which is the whole reason for asking
     * twice, so the mismatch has to change nothing at all.
     */
    public function testAMistypedRepeatChangesNothing(): void
    {
        $user   = MailFixtures::user($this->em, 'pwd-typo');
        $before = $this->storedHash($user);

        $this->command->setInputs(['a-long-enough-password', 'a-long-enuogh-password']);
        $exit = $this->command->execute(['email' => (string) $user->email]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame($before, $this->storedHash($user));
    }

    public function testAPasswordUnderTheFloorIsRefused(): void
    {
        $user   = MailFixtures::user($this->em, 'pwd-short');
        $before = $this->storedHash($user);

        $exit = $this->command->execute([
            'email'      => (string) $user->email,
            '--password' => 'brief',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame($before, $this->storedHash($user));
    }

    /**
     * A removed user keeps their rows and loses their password, and that empty
     * hash is what makes the row unable to authenticate. Handing one a working
     * password would undo somebody's decision to remove them — and the address
     * to do it with is printed in nothing, but it is derivable: the admin panel
     * writes `deleted-<id>@invalid`.
     */
    public function testARemovedUserIsNotLetBackIn(): void
    {
        $user = MailFixtures::user($this->em, 'pwd-gone');

        $user->email     = sprintf('deleted-%d@invalid', $user->id);
        $user->password  = '';
        $user->deletedAt = new DateTimeImmutable();
        $this->em->flush();

        $exit = $this->command->execute([
            'email'      => (string) $user->email,
            '--password' => 'a-long-enough-password',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame('', $this->storedHash($user));
    }

    public function testAnUnknownAddressIsRefused(): void
    {
        $exit = $this->command->execute([
            'email'      => 'nobody-at-all@example.test',
            '--password' => 'a-long-enough-password',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No user found', $this->command->getDisplay());
    }

    /**
     * The two losses are separate commands, and somebody who has had their
     * password reset while still enrolled needs telling that they are not in
     * yet — otherwise the next report is "the reset did not work".
     */
    public function testItSaysSoWhenASecondFactorIsStillInTheWay(): void
    {
        $user = MailFixtures::user($this->em, 'pwd-2fa');

        // Through the entity's own enrolment methods, so the fixture is in the
        // state a completed enrolment leaves behind rather than one invented
        // here — and because both columns are private(set).
        $user->startTotpEnrolment('JBSWY3DPEHPK3PXP');
        $user->confirmTotp();
        $this->em->flush();

        $this->command->execute([
            'email'      => (string) $user->email,
            '--password' => 'a-long-enough-password',
        ]);

        self::assertStringContainsString('app:user:2fa-disable', $this->command->getDisplay());
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    /** Read back out of the database: the point is that the change is flushed. */
    private function storedHash(User $user): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT password FROM "user" WHERE id = ?',
            [$user->id],
        );
    }
}
