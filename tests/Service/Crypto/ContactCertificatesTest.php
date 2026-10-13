<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\SignatureState;
use App\Entity\Crypto\ContactCertificate;
use App\Entity\Mail\Account;
use App\Repository\Crypto\ContactCertificateRepository;
use App\Service\Crypto\ContactCertificates;
use App\Service\Crypto\SignatureVerifier;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Smime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Contacts' S/MIME certificates: picked up from signed mail whose signature
 * checks out, and trusted the way the rule in ContactCertificates says.
 *
 * Real certificates (Smime) and real signed mail, checked by the real
 * SignatureVerifier through the raw storage ingest fills. The authority is
 * made trusted for the test (SMIME_CA_FILE), which is what "chains to a
 * system CA" means here; a self-signed certificate is the other kind.
 */
final class ContactCertificatesTest extends KernelTestCase
{
    private const string SENDER = 'smime-sender@example.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private Smime $authority;

    protected function setUp(): void
    {
        $this->authority = Smime::authority();
        $this->authority->trustAuthority();

        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'certs'));
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        Smime::untrust();

        parent::tearDown();
    }

    public function testACertificateAnAuthorityVouchesForIsCollectedAsVerified(): void
    {
        $sender = Smime::issue(self::SENDER, $this->authority);

        self::assertSame(SignatureState::Valid, $this->verify($sender->signedMessage('Hello.'))['state'] ?? null);

        $kept = $this->certificates();

        self::assertCount(1, $kept);
        self::assertSame($sender->fingerprint, $kept[0]->fingerprint);
        self::assertTrue($kept[0]->verified);
        self::assertTrue($kept[0]->isCurrent());
    }

    public function testASelfSignedCertificateIsTrustedOnFirstUseAndAChangeWaits(): void
    {
        $first  = Smime::issue(self::SENDER);
        $second = Smime::issue(self::SENDER);

        self::assertSame(SignatureState::Untrusted, $this->verify($first->signedMessage('One.'))['state'] ?? null);
        self::assertSame(SignatureState::Untrusted, $this->verify($second->signedMessage('Two.'))['state'] ?? null);

        $kept = $this->byFingerprint();

        self::assertFalse($kept[$first->fingerprint]->verified);
        self::assertSame(ContactKeyStatus::Current, $kept[$first->fingerprint]->status);
        self::assertSame(ContactKeyStatus::Changed, $kept[$second->fingerprint]->status, 'a different self-signed certificate waits for the person');
        self::assertSame($first->fingerprint, $this->service()->usable($this->account->usr, self::SENDER)?->fingerprint);
    }

    /** Renewal: the authority vouches for the new certificate as it did for the old. */
    public function testAVerifiedCertificateReplacesAVerifiedOne(): void
    {
        $old = Smime::issue(self::SENDER, $this->authority);
        $new = Smime::issue(self::SENDER, $this->authority);

        $this->verify($old->signedMessage('Old.'));
        $this->verify($new->signedMessage('New.'));

        $kept = $this->certificates();

        self::assertCount(1, $kept);
        self::assertSame($new->fingerprint, $kept[0]->fingerprint);
    }

    /** Who vouches for the address changed, and that is the person's to accept. */
    public function testAVerifiedCertificateDoesNotDisplaceASelfSignedOneInUse(): void
    {
        $selfSigned = Smime::issue(self::SENDER);
        $verified   = Smime::issue(self::SENDER, $this->authority);

        $this->verify($selfSigned->signedMessage('One.'));
        $this->verify($verified->signedMessage('Two.'));

        self::assertSame(ContactKeyStatus::Changed, $this->byFingerprint()[$verified->fingerprint]->status);

        $this->service()->accept($this->account->usr, (int) $this->byFingerprint()[$verified->fingerprint]->id);

        self::assertSame($verified->fingerprint, $this->service()->usable($this->account->usr, self::SENDER)?->fingerprint);
        self::assertCount(1, $this->certificates(), 'accepting removes the one it replaces');
    }

    public function testNothingIsCollectedFromASignatureThatDoesNotCheckOut(): void
    {
        $sender = Smime::issue(self::SENDER, $this->authority);
        $raw    = str_replace('Hello.', 'Hallo.', $sender->signedMessage('Hello.'));

        self::assertSame(SignatureState::Invalid, $this->verify($raw)['state'] ?? null);
        self::assertSame([], $this->certificates());
    }

    /** A good signature by somebody else is the forgery, not a certificate to keep. */
    public function testNothingIsCollectedForAnAddressTheCertificateDoesNotName(): void
    {
        $other = Smime::issue('somebody-else@example.test', $this->authority);

        self::assertSame(SignatureState::Invalid, $this->verify($other->signedMessage('Hello.', self::SENDER))['state'] ?? null);
        self::assertSame([], $this->certificates());
    }

    /** plMail encrypts with RSA key transport; an EC certificate is no use to it. */
    public function testACertificateMailCannotBeEncryptedToIsNotKept(): void
    {
        $sender = Smime::issue(self::SENDER, $this->authority, ec: true);

        self::assertSame(ContactKeyOutcome::Unusable, $this->service()->record($this->account->usr, self::SENDER, $sender->certificate, true));
        self::assertSame([], $this->certificates());
    }

    public function testThePersonsOwnAddressesAreNotContacts(): void
    {
        $own = Smime::issue($this->account->username, $this->authority);

        self::assertSame(ContactKeyOutcome::Unusable, $this->service()->record($this->account->usr, $this->account->username, $own->certificate, true));
    }

    /**
     * A signature the browser found inside encrypted mail: the server judges
     * the certificate it is sent, and keeps it.
     */
    public function testTheSignerOfASignatureInsideEncryptedMailIsJudgedAndKept(): void
    {
        $verified   = Smime::issue(self::SENDER, $this->authority);
        $selfSigned = Smime::issue(self::SENDER);
        $stranger   = Smime::issue('stranger@example.test', $this->authority);
        $user       = $this->account->usr;

        self::assertSame(SignatureState::Valid, $this->service()->signerState($user, self::SENDER, $verified->certificate, '')['state']);
        self::assertSame(SignatureState::KeyChanged, $this->service()->signerState($user, self::SENDER, $selfSigned->certificate, '')['state']);
        self::assertSame(SignatureState::Invalid, $this->service()->signerState($user, self::SENDER, $stranger->certificate, '')['state']);
        self::assertSame(SignatureState::Invalid, $this->service()->signerState($user, self::SENDER, 'not a certificate', '')['state']);
        self::assertCount(2, $this->certificates());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function verify(string $raw): ?array
    {
        $message              = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = self::SENDER;
        $message->crypto      = MessageCrypto::SmimeSigned;

        static::getContainer()->get(RawMessageResolver::class)->store($message, $raw);
        $this->em->flush();

        return static::getContainer()->get(SignatureVerifier::class)->verify($message);
    }

    private function service(): ContactCertificates
    {
        return static::getContainer()->get(ContactCertificates::class);
    }

    /** @return list<ContactCertificate> */
    private function certificates(): array
    {
        return static::getContainer()->get(ContactCertificateRepository::class)->findForAddress($this->account->usr, self::SENDER);
    }

    /** @return array<string, ContactCertificate> */
    private function byFingerprint(): array
    {
        $rows = [];

        foreach ($this->certificates() as $row) {
            $rows[$row->fingerprint] = $row;
        }

        return $rows;
    }
}
