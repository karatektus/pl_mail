<?php

declare(strict_types=1);

namespace App\Tests\Command\Maintenance;

use App\Service\User\DevicePairingService;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Enrolling a device from the shell, for the case the web UI cannot serve.
 *
 * The output is the product here — an operator copies the printed URI into a
 * phone — so "it printed something" is not enough. The tests redeem what was
 * printed and check a real credential comes back, for the right user.
 */
final class DevicePairCommandTest extends KernelTestCase
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
            new Application(self::$kernel)->find('app:device:pair'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testThePrintedCodeRedeemsIntoACredentialForThatUser(): void
    {
        $user = MailFixtures::user($this->em, 'pair');

        $exit = $this->command->execute([
            'email'      => (string) $user->email,
            '--base-url' => 'https://mail.example.test',
        ]);

        self::assertSame(Command::SUCCESS, $exit);

        $redeemed = $this->pairing()->redeem($this->printedCode(), 'Test device');

        self::assertNotNull($redeemed, 'The code the operator was handed has to work.');
        self::assertSame((string) $user->email, $redeemed['username']);
        self::assertNotSame('', $redeemed['secret']);
    }

    public function testTheUriCarriesTheHostTheDeviceWasToldToReach(): void
    {
        $user = MailFixtures::user($this->em, 'pair-host');

        $this->command->execute([
            'email'      => (string) $user->email,
            '--base-url' => 'https://mail.example.test/',
        ]);

        // The device reads the host out of the URI, so a mangled or missing one
        // is a QR code that scans and then cannot connect to anything.
        self::assertStringContainsString(
            'host=' . rawurlencode('https://mail.example.test'),
            $this->command->getDisplay(),
        );
    }

    /** Codes are single-use; a second redeem of the same one must find nothing. */
    public function testACodeCannotBeRedeemedTwice(): void
    {
        $user = MailFixtures::user($this->em, 'pair-once');

        $this->command->execute(['email' => (string) $user->email]);

        $code = $this->printedCode();

        self::assertNotNull($this->pairing()->redeem($code, 'First device'));
        self::assertNull($this->pairing()->redeem($code, 'Second device'));
    }

    public function testAnUnknownAddressIssuesNoCode(): void
    {
        $exit = $this->command->execute(['email' => 'nobody-at-all@example.test']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringNotContainsString('plmail://pair', $this->command->getDisplay());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** The code as an operator would take it: out of the printed URI. */
    private function printedCode(): string
    {
        self::assertSame(
            1,
            preg_match('/plmail:\/\/pair\?host=[^&]+&code=([^\s]+)/', $this->command->getDisplay(), $matches),
            'The command has to print a pairing URI, since that is all the operator gets.',
        );

        return rawurldecode($matches[1]);
    }

    private function pairing(): DevicePairingService
    {
        return self::getContainer()->get(DevicePairingService::class);
    }
}
