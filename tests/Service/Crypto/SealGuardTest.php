<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Entity\Crypto\ContactKey;
use App\Entity\User\User;
use App\Service\Crypto\SealGuard;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A message encrypted to a contact goes out only while that contact's key is
 * still the one it was encrypted to, and still usable.
 *
 * Asked again when a scheduled send leaves, which is why it is not enough to
 * have been right when the person pressed the button: by Monday the key may
 * have been replaced by another or revoked by its owner. Real keys (Gpg), so
 * revocation is what gpg says it is. The person's own key as a recipient is
 * SealedSendTest's.
 */
final class SealGuardTest extends KernelTestCase
{
    private const string CONTACT = 'contact@example.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->user = MailFixtures::user($this->em, 'seal');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheContactsCurrentKeyLetsItThrough(): void
    {
        $key = new Gpg(self::CONTACT);
        $this->contactKey($key->fingerprint, $key->armored, ContactKeyStatus::Current);

        self::assertNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$key->fingerprint]));
    }

    public function testAKeyReplacedSinceTheMessageWasEncryptedStopsIt(): void
    {
        $old = new Gpg(self::CONTACT);
        $new = new Gpg(self::CONTACT);
        $this->contactKey($new->fingerprint, $new->armored, ContactKeyStatus::Current);

        self::assertNotNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$old->fingerprint]));
    }

    public function testAKeyRevokedSinceStopsIt(): void
    {
        $key = new Gpg(self::CONTACT);
        $this->contactKey($key->fingerprint, $key->revoked(), ContactKeyStatus::Current);

        self::assertNotNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$key->fingerprint]));
    }

    public function testARecipientWithoutAKeyStopsIt(): void
    {
        self::assertNotNull($this->guard()->keyRefusal($this->user, ['stranger@example.test'], []));
    }

    public function testAKeyStillWaitingToBeAcceptedIsNotTheOneToEncryptTo(): void
    {
        $key = new Gpg(self::CONTACT);
        $this->contactKey($key->fingerprint, $key->armored, ContactKeyStatus::Changed);

        self::assertNotNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$key->fingerprint]));
    }

    private function contactKey(string $fingerprint, string $publicKey, ContactKeyStatus $status): void
    {
        $this->em->persist(new ContactKey($this->user, self::CONTACT, $fingerprint, $publicKey, ContactKeyOrigin::Manual, $status, null, new DateTimeImmutable()));
        $this->em->flush();
    }

    private function guard(): SealGuard
    {
        return static::getContainer()->get(SealGuard::class);
    }
}
