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
 * The documented way back in after losing the phone and the recovery codes.
 *
 * Two failure modes matter here and they point in opposite directions. If it
 * under-deletes, the user believes their second factor is gone while a stale
 * secret or an unused recovery code still opens the account. If it fires
 * without being asked, one mistyped shell command silently drops the second
 * factor off a mailbox nobody meant to touch. Both are covered below.
 */
final class DisableTwoFactorCommandTest extends KernelTestCase
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
            new Application(self::$kernel)->find('app:user:2fa-disable'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItLeavesNothingBehindThatCouldStillOpenTheAccount(): void
    {
        $user = $this->enrolledUser();

        $exit = $this->command->execute([
            'email'   => (string) $user->email,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFalse($user->isTotpAuthenticationEnabled());

        // The recovery codes are alternative proofs of the same factor, so a
        // surviving set would mean 2FA is only cosmetically off.
        self::assertSame(0, $user->backupCodeCount);
        self::assertNull($this->storedTotpSecret($user));
    }

    public function testAnsweringNoAtThePromptChangesNothing(): void
    {
        $user = $this->enrolledUser();

        $this->command->setInputs(['no']);
        $exit = $this->command->execute(['email' => (string) $user->email], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue(
            $user->isTotpAuthenticationEnabled(),
            'Declining the prompt must not have removed the second factor.',
        );
        self::assertNotNull($this->storedTotpSecret($user));
    }

    /**
     * The default is "no", so an unattended run — a script, a `-n` invocation,
     * a stray Enter — must fall through to leaving 2FA alone.
     */
    public function testWithoutForceAndWithoutATerminalNothingIsRemoved(): void
    {
        $user = $this->enrolledUser();

        $exit = $this->command->execute(['email' => (string) $user->email], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($user->isTotpAuthenticationEnabled());
    }

    public function testAUserWhoNeverEnrolledIsReportedRatherThanTouched(): void
    {
        $user = MailFixtures::user($this->em, 'no2fa');

        $exit = $this->command->execute([
            'email'   => (string) $user->email,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('already off', $this->command->getDisplay());
    }

    public function testAnUnknownAddressIsRefused(): void
    {
        $exit = $this->command->execute([
            'email'   => 'nobody-at-all@example.test',
            '--force' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No user found', $this->command->getDisplay());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function enrolledUser(): User
    {
        $user = MailFixtures::user($this->em, '2fa');

        // Through the entity's own enrolment methods, so the fixture is in the
        // state a completed enrolment leaves behind rather than one invented
        // here.
        $user->startTotpEnrolment('JBSWY3DPEHPK3PXP');
        $user->confirmTotp();
        $user->backupCodes = [User::hashBackupCode('abcd-efgh')];

        $this->em->flush();

        self::assertTrue($user->isTotpAuthenticationEnabled(), 'The fixture must start enrolled.');

        return $user;
    }

    private function storedTotpSecret(User $user): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT totp_secret FROM "user" WHERE id = ?',
            [$user->id],
        );

        return false === $value || null === $value ? null : (string) $value;
    }
}
