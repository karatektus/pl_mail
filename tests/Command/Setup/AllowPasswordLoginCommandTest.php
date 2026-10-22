<?php

declare(strict_types=1);

namespace App\Tests\Command\Setup;

use App\Entity\Oidc\OidcConfig;
use App\Repository\Oidc\OidcConfigRepository;
use App\Service\Oidc\OidcSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The way back in when the provider is down: the password form returns and
 * nothing else about single sign-on changes.
 *
 * "Nothing else" is half the point. The person running this is locked out and
 * in a hurry; a command that also dropped the provider would trade one outage
 * for another, with everybody who only has a provider identity shut out next.
 */
final class AllowPasswordLoginCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:sso:allow-password-login'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testItBringsThePasswordFormBackAndLeavesTheProvider(): void
    {
        $row                        = new OidcConfig();
        $row->issuer                = 'https://auth.example.com/';
        $row->clientId              = 'plmail';
        $row->clientSecret          = 'the-secret';
        $row->passwordLoginDisabled = true;

        $this->em->persist($row);
        $this->em->flush();

        self::assertFalse(self::getContainer()->get(OidcSettings::class)->current()->allowsPasswordLogin());

        $exit = $this->command->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Password sign-in is allowed again', $this->command->getDisplay());

        $this->em->clear();
        $current = self::getContainer()->get(OidcConfigRepository::class)->current();

        self::assertNotNull($current);
        self::assertFalse($current->passwordLoginDisabled);
        self::assertTrue($current->isComplete(), 'the provider itself is left alone');
        self::assertTrue(self::getContainer()->get(OidcSettings::class)->current()->allowsPasswordLogin());
    }

    /**
     * Run on an install that never set it, it says so and succeeds: the person
     * may be guessing at the cause, and a failure here would send them looking
     * for a problem with the command instead of the environment.
     */
    public function testWithNothingSavedItSaysSoAndPointsAtTheEnvironment(): void
    {
        $exit = $this->command->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('does not hold "single sign-on only"', $this->command->getDisplay());
        self::assertStringContainsString('OIDC_DISABLE_PASSWORD_LOGIN', $this->command->getDisplay());
    }
}
