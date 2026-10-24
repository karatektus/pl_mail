<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\WkdState;
use App\Repository\User\UserRepository;
use App\Service\Crypto\Keyring;
use App\Service\Crypto\PublicKeyReader;
use App\Service\Crypto\SendingKey;
use App\Service\Crypto\WkdDirectory;
use App\Service\Crypto\WkdKeyExport;
use App\Service\Crypto\WkdLookup;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Crypto\SeedsWkdPeople;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;

/**
 * Which addresses plMail's Web Key Directory speaks for, decided before any
 * request: the installation's domain list as an operator writes it, and the
 * one address per person the installation can vouch for.
 *
 * The second is the rule that keeps the directory from being a way to put a
 * key under somebody else's address — an alias is whatever its owner typed —
 * so it is pinned on its own, apart from the routes that apply it.
 */
final class WkdDirectoryTest extends KernelTestCase
{
    use SeedsWkdPeople;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheDomainListIsReadAsAnOperatorWritesIt(): void
    {
        $directory = $this->directory(" Example.ORG, example.net\nbücher.example ,, localhost, 10.0.0.1, example.org ");

        self::assertSame(['example.org', 'example.net', 'xn--bcher-kva.example'], $directory->domains());
        self::assertTrue($directory->serves('BÜCHER.example'));
        self::assertFalse($directory->serves('gmail.com'));
        self::assertFalse($this->directory('')->isEnabled());
    }

    public function testADirectRequestUnderAForeignHostIsOnlyGuessedWithOneDomain(): void
    {
        self::assertSame('example.org', $this->directory('example.org')->directDomain('mail.example.org'));
        self::assertSame('example.net', $this->directory('example.org,example.net')->directDomain('EXAMPLE.net'));
        self::assertNull($this->directory('example.org,example.net')->directDomain('mail.example.org'));
        self::assertNull($this->directory('')->directDomain('example.org'));
    }

    /**
     * An alias with a perfectly good key is listed — so the person learns why
     * it is not published — and cannot be switched on.
     */
    public function testOnlyTheSignInAddressIsPublishedAndAnAliasCannotBeSwitchedOn(): void
    {
        $alias            = 'alias-' . bin2hex(random_bytes(4)) . '@plmail.test';
        [$user, $userKey] = $this->seedWkdPerson(extraAddress: $alias);

        $this->seedWkdKey($user, $userKey, new Gpg((string) $user->email), (string) $user->email);
        $this->seedWkdKey($user, $userKey, new Gpg($alias), $alias);

        $directory = $this->directory('plmail.test');
        $states    = [];

        foreach ($directory->entriesFor($user) as $entry) {
            $states[$entry->email] = $entry->state;
        }

        self::assertSame([(string) $user->email => WkdState::Published, $alias => WkdState::NotSignInAddress], $states);
        self::assertFalse($directory->setPublished($user, $alias, true));
        self::assertSame([], $user->wkdUnpublished);
    }

    public function testAnExpiredKeyLeavesTheAddressWithNothingToPublish(): void
    {
        [$user, $userKey] = $this->seedWkdPerson();
        $this->seedWkdKey($user, $userKey, new Gpg((string) $user->email), (string) $user->email, new DateTimeImmutable('-1 minute'));

        self::assertSame(WkdState::NoKey, $this->directory('plmail.test')->entryFor($user, (string) $user->email)?->state);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    private function directory(string $domains): WkdDirectory
    {
        $container = self::getContainer();

        return new WkdDirectory(
            $container->get(UserRepository::class),
            $container->get(SendingKey::class),
            $container->get(Keyring::class),
            $container->get(WkdKeyExport::class),
            $container->get(WkdLookup::class),
            $container->get(PublicKeyReader::class),
            $this->em,
            $container->get(ClockInterface::class),
            $domains,
        );
    }
}
