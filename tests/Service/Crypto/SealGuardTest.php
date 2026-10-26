<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Entity\Crypto\ContactCertificate;
use App\Entity\Crypto\ContactKey;
use App\Entity\Crypto\SmimeCertificate;
use App\Entity\Crypto\UserKey;
use App\Entity\User\User;
use App\Service\Crypto\CertificateReader;
use App\Service\Crypto\SealGuard;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Crypto\Smime;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A message encrypted to a contact goes out only while that contact's key is
 * still the one it was encrypted to, and still usable.
 *
 * Asked again when a scheduled send leaves, which is why it is not enough to
 * have been right when the person pressed the button: by Monday the key may
 * have been replaced by another or revoked by its owner. Real keys (Gpg), so
 * revocation is what gpg says it is. The person's own key as a recipient is
 * SealedSendTest's.
 *
 * The same for S/MIME (phase 6c), with real certificates (Smime): the
 * contact's Current certificate and no other, not expired — at sealing and
 * again when the send leaves, which is what moving the clock stands for —
 * and the person's own certificate both as a recipient and as the signer.
 */
final class SealGuardTest extends KernelTestCase
{
    private const string CONTACT = 'contact@example.test';
    private const string OWN     = 'own@example.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();

        // The clock every service here reads, so a test can move it past a
        // certificate's end. A minute ahead: a certificate issued in this
        // same second has a notBefore the clock would otherwise precede.
        $this->clock = new MockClock('+1 minute');
        static::getContainer()->set('clock', $this->clock);

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

    // ── S/MIME ────────────────────────────────────────────────────────────────

    public function testTheContactsCurrentCertificateLetsAnSmimeMessageThrough(): void
    {
        $certificate = Smime::issue(self::CONTACT);
        $this->contactCertificate($certificate, ContactKeyStatus::Current);

        self::assertNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$certificate->fingerprint], SealGuard::SCHEME_SMIME));
    }

    public function testACertificateReplacedSinceStopsAnSmimeMessage(): void
    {
        $old = Smime::issue(self::CONTACT);
        $new = Smime::issue(self::CONTACT);
        $this->contactCertificate($new, ContactKeyStatus::Current);
        $this->contactCertificate($old, ContactKeyStatus::Changed);

        self::assertNotNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$old->fingerprint], SealGuard::SCHEME_SMIME));
    }

    /**
     * The certificates last thirty days; a send scheduled for two months on
     * finds the contact's expired, and no recipient would open it any more.
     */
    public function testACertificateThatExpiredBeforeTheSendStopsIt(): void
    {
        $certificate = Smime::issue(self::CONTACT);
        $this->contactCertificate($certificate, ContactKeyStatus::Current);

        self::assertNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$certificate->fingerprint], SealGuard::SCHEME_SMIME));

        $this->clock->modify('+60 days');

        self::assertNotNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$certificate->fingerprint], SealGuard::SCHEME_SMIME));
    }

    public function testAnOpenPgpKeyIsNoCertificate(): void
    {
        $key = new Gpg(self::CONTACT);
        $this->contactKey($key->fingerprint, $key->armored, ContactKeyStatus::Current);

        self::assertNotNull($this->guard()->keyRefusal($this->user, [self::CONTACT], [$key->fingerprint], SealGuard::SCHEME_SMIME));
    }

    /** One's own address is reached through one's own certificate, and that signs. */
    public function testTheOwnCertificateServesTheOwnAddressAndSignsUntilItExpires(): void
    {
        $own = Smime::issue(self::OWN);
        $this->ownCertificate($own);

        self::assertNull($this->guard()->keyRefusal($this->user, [self::OWN], [$own->fingerprint], SealGuard::SCHEME_SMIME));
        $account = MailFixtures::account($this->em, $this->user, self::OWN);

        self::assertNull($this->guard()->signerRefusal($account, self::OWN, $own->fingerprint));
        self::assertNull($this->guard()->signerRefusal($account, 'Display Name', $own->fingerprint), 'a From that is no address stands for the account\'s own');
        self::assertNotNull($this->guard()->signerRefusal($account, self::OWN, str_repeat('ab', 32)), 'another certificate is not the one that signs');
        self::assertNotNull($this->guard()->signerRefusal($account, self::CONTACT, $own->fingerprint), 'not for another From address');

        $this->clock->modify('+60 days');

        self::assertNotNull($this->guard()->signerRefusal($account, self::OWN, $own->fingerprint));
        self::assertNotNull($this->guard()->keyRefusal($this->user, [self::OWN], [$own->fingerprint], SealGuard::SCHEME_SMIME));
    }

    private function contactCertificate(Smime $certificate, ContactKeyStatus $status): void
    {
        $info = static::getContainer()->get(CertificateReader::class)->read($certificate->certificate);
        self::assertNotNull($info);

        $this->em->persist(new ContactCertificate($this->user, self::CONTACT, $info->fingerprint, $info->pem, false, $status, $info->subject, $info->issuer, $info->notBefore, $info->notAfter, new DateTimeImmutable()));
        $this->em->flush();
    }

    private function ownCertificate(Smime $certificate): void
    {
        $info = static::getContainer()->get(CertificateReader::class)->read($certificate->certificate);
        self::assertNotNull($info);

        $userKey = new UserKey($this->user, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $this->em->persist($userKey);
        $this->em->persist(new SmimeCertificate($this->user, $userKey, $info->fingerprint, $info->pem, '', 'v1.x.y', [self::OWN], $info->subject, $info->issuer, $info->notBefore, $info->notAfter));
        $this->em->flush();
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
