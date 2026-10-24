<?php

declare(strict_types=1);

namespace App\Tests\Command\Setup;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unlinking clears both halves of the identity, and only on the account named.
 *
 * Both halves, because the pair is what the unique index and the resolver read:
 * a subject left behind without its issuer would be a row nothing matches and
 * nothing can link over. And a mistyped address has to fail rather than report
 * success, since the person waiting to get back in would be told it was done.
 */
final class UnlinkOidcIdentityCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:user:oidc-unlink'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testItForgetsTheIssuerAndTheSubject(): void
    {
        $user = new User();
        $user->email       = 'oidc-unlink@plmail.test';
        $user->nameFirst   = 'Oidc';
        $user->nameLast    = 'Unlink';
        $user->password    = '';
        $user->oidcIssuer  = 'https://auth.example.com/application/o/plmail/';
        $user->oidcSubject = 'subject-gone';

        $this->em->persist($user);
        $this->em->flush();

        $exit = $this->command->execute(['email' => 'oidc-unlink@plmail.test']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNull($user->oidcIssuer);
        self::assertNull($user->oidcSubject);
    }

    public function testAnAddressNobodyHoldsFails(): void
    {
        $exit = $this->command->execute(['email' => 'oidc-nobody@plmail.test']);

        self::assertSame(Command::FAILURE, $exit);
    }
}
