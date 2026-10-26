<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\SignatureState;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\ContactKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Crypto\GnupgHome;
use App\Service\Crypto\SignatureVerifier;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * A signature is believed exactly as far as it deserves.
 *
 * Real signatures, made here — an S/MIME certificate from OpenSSL, a PGP key
 * from gpg — over real raw messages, checked through the same raw storage the
 * ingest path fills. The claims worth a test are the ones a lenient checker
 * gets wrong: a self-signed certificate is not "valid", a message changed after
 * signing is not "untrusted", and a perfectly good signature by somebody other
 * than the sender is the forgery to flag. With contacts' keys: only a pinned
 * one makes a signature Valid, and a key other than the known one for the
 * sender is said to be exactly that.
 */
final class SignatureVerifierTest extends KernelTestCase
{
    private const string SIGNER = 'signer@example.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'sig'));
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── S/MIME ────────────────────────────────────────────────────────────────

    /** Intact, but nobody vouches for a self-signed certificate. */
    public function testASelfSignedSmimeSignatureIsIntactButUntrusted(): void
    {
        $result = $this->verify($this->smimeSigned('Hello.'), MessageCrypto::SmimeSigned, self::SIGNER);

        self::assertSame(SignatureState::Untrusted, $result['state'] ?? null);
        self::assertSame(self::SIGNER, $result['signer'] ?? null);
    }

    public function testAnSmimeMessageChangedAfterSigningIsInvalid(): void
    {
        $raw = str_replace('Hello.', 'Hallo.', $this->smimeSigned('Hello.'));

        self::assertSame(SignatureState::Invalid, $this->verify($raw, MessageCrypto::SmimeSigned, self::SIGNER)['state'] ?? null);
    }

    /** A good signature by somebody else is the forgery worth catching. */
    public function testAnSmimeSignatureByAnotherAddressThanTheSenderIsInvalid(): void
    {
        self::assertSame(
            SignatureState::Invalid,
            $this->verify($this->smimeSigned('Hello.'), MessageCrypto::SmimeSigned, 'someone-else@example.test')['state'] ?? null,
        );
    }

    // ── PGP ───────────────────────────────────────────────────────────────────

    public function testAPgpSignatureByAnUnknownKeyNamesTheKey(): void
    {
        [$raw, $fingerprint] = $this->pgpSigned('Hello.');

        $result = $this->verify($raw, MessageCrypto::PgpSigned, self::SIGNER);

        self::assertSame(SignatureState::UnknownKey, $result['state'] ?? null);
        self::assertNotNull($result['signer'] ?? null);
        self::assertStringEndsWith(substr((string) $result['signer'], -16), $fingerprint, 'the signer is not the signing key');
    }

    /** The person's own key is the one key plMail can vouch for. */
    public function testAPgpSignatureByThePersonsOwnKeyIsValid(): void
    {
        [$raw, $fingerprint, $publicKey] = $this->pgpSigned('Hello.');

        $userKey = new UserKey($this->account->usr, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $this->em->persist($userKey);
        $this->em->persist(new AddressKey($this->account->usr, $userKey, $fingerprint, $publicKey, "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nx\n", 'v1.x.y', AddressKeyOrigin::Imported, null, null));
        $this->em->flush();

        $result = $this->verify($raw, MessageCrypto::PgpSigned, self::SIGNER);

        self::assertSame(SignatureState::Valid, $result['state'] ?? null);
        self::assertSame($fingerprint, $result['signer'] ?? null);
    }

    public function testAPgpMessageChangedAfterSigningIsInvalid(): void
    {
        [$raw] = $this->pgpSigned('Hello.');

        self::assertSame(
            SignatureState::Invalid,
            $this->verify(str_replace('Hello.', 'Hallo.', $raw), MessageCrypto::PgpSigned, self::SIGNER, autocrypt: true)['state'] ?? null,
        );
    }

    // ── Contacts' keys ────────────────────────────────────────────────────────

    /** Intact, and the key is the sender's known one — but nobody confirmed it. */
    public function testASignatureByTheSendersUnpinnedContactKeyIsUntrusted(): void
    {
        $key = new Gpg(self::SIGNER);
        $this->knowContactKey($key);

        $result = $this->verify($key->signedMessage('Hello.'), MessageCrypto::PgpSigned, self::SIGNER);

        self::assertSame(SignatureState::Untrusted, $result['state'] ?? null);
        self::assertSame($key->fingerprint, $result['signer'] ?? null);
    }

    public function testASignatureByAPinnedContactKeyIsValid(): void
    {
        $key = new Gpg(self::SIGNER);
        $this->knowContactKey($key, pinned: true);

        self::assertSame(SignatureState::Valid, $this->verify($key->signedMessage('Hello.'), MessageCrypto::PgpSigned, self::SIGNER)['state'] ?? null);
    }

    /**
     * plMail knows a key for the sender, and this was signed with another one
     * it does not have: whoever signed, it was not with the known key.
     */
    public function testASignatureByAMissingKeyWhileTheSenderHasAKnownOneSaysTheKeyChanged(): void
    {
        $known  = new Gpg(self::SIGNER);
        $signer = new Gpg(self::SIGNER);
        $this->knowContactKey($known, pinned: true);

        $result = $this->verify($signer->signedMessage('Hello.'), MessageCrypto::PgpSigned, self::SIGNER);

        self::assertSame(SignatureState::KeyChanged, $result['state'] ?? null);
    }

    /** A new key in the message's own Autocrypt header is news, not a pass. */
    public function testAnAutocryptKeyOtherThanTheKnownOneSaysTheKeyChanged(): void
    {
        $known  = new Gpg(self::SIGNER);
        $signer = new Gpg(self::SIGNER);
        $this->knowContactKey($known);

        $raw    = $signer->signedMessage('Hello.');
        $result = $this->verify($raw, MessageCrypto::PgpSigned, self::SIGNER, autocryptKey: $signer->binary);

        self::assertSame(SignatureState::KeyChanged, $result['state'] ?? null);
        self::assertSame($signer->fingerprint, $result['signer'] ?? null);
    }

    public function testAChangedKeyWaitingForADecisionSaysTheKeyChanged(): void
    {
        $known  = new Gpg(self::SIGNER);
        $signer = new Gpg(self::SIGNER);
        $this->knowContactKey($known);
        $this->knowContactKey($signer, status: ContactKeyStatus::Changed);

        self::assertSame(SignatureState::KeyChanged, $this->verify($signer->signedMessage('Hello.'), MessageCrypto::PgpSigned, self::SIGNER)['state'] ?? null);
    }

    /** An Autocrypt header naming another address plants nothing. */
    public function testAnAutocryptHeaderForAnotherAddressIsNotUsed(): void
    {
        $signer = new Gpg(self::SIGNER);
        $raw    = $signer->signedMessage('Hello.');

        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = self::SIGNER;
        $message->crypto      = MessageCrypto::PgpSigned;
        $message->headers     = ['autocrypt' => 'addr=other@example.test; keydata=' . base64_encode($signer->binary)];
        static::getContainer()->get(RawMessageResolver::class)->store($message, $raw);
        $this->em->flush();

        self::assertSame(SignatureState::UnknownKey, static::getContainer()->get(SignatureVerifier::class)->verify($message)['state'] ?? null);
    }

    /** A key revoked since it signed vouches for nothing, pinned or not. */
    public function testASignatureByARevokedKeyIsInvalid(): void
    {
        $key = new Gpg(self::SIGNER);
        $raw = $key->signedMessage('Hello.');

        $this->em->persist(new ContactKey($this->account->usr, self::SIGNER, $key->fingerprint, $key->revoked(), ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable()));
        $this->em->flush();

        self::assertSame(SignatureState::Invalid, $this->verify($raw, MessageCrypto::PgpSigned, self::SIGNER)['state'] ?? null);
    }

    /** A good signature by a key that carries no user ID for the sender is the forgery to flag. */
    public function testAGoodPgpSignatureByAKeyWithoutTheSendersAddressIsInvalid(): void
    {
        $key = new Gpg(self::SIGNER);
        $this->ownKey($key);

        $result = $this->verify($key->signedMessage('Hello.', 'someone.else@example.test'), MessageCrypto::PgpSigned, 'someone.else@example.test');

        self::assertSame(SignatureState::Invalid, $result['state'] ?? null);
        self::assertSame($key->fingerprint, $result['signer'] ?? null);
    }

    public function testAnInlineSignatureByAPinnedContactKeyIsValid(): void
    {
        $key = new Gpg(self::SIGNER);
        $this->knowContactKey($key, pinned: true);

        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = self::SIGNER;
        $message->crypto      = MessageCrypto::PgpInlineSigned;
        $message->bodyText    = $key->clearSigned("Hello.\n");
        static::getContainer()->get(RawMessageResolver::class)->store($message, "From: " . self::SIGNER . "\r\n\r\n" . $message->bodyText);
        $this->em->flush();

        $result = static::getContainer()->get(SignatureVerifier::class)->verify($message);

        self::assertSame(SignatureState::Valid, $result['state'] ?? null);
        self::assertSame($key->fingerprint, $result['signer'] ?? null);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function ownKey(Gpg $key): void
    {
        $userKey = new UserKey($this->account->usr, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $this->em->persist($userKey);
        $this->em->persist(new AddressKey($this->account->usr, $userKey, $key->fingerprint, $key->armored, "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nx\n", 'v1.x.y', AddressKeyOrigin::Imported, null, null));
        $this->em->flush();
    }

    private function knowContactKey(Gpg $key, bool $pinned = false, ContactKeyStatus $status = ContactKeyStatus::Current): void
    {
        $row = new ContactKey($this->account->usr, self::SIGNER, $key->fingerprint, $key->armored, ContactKeyOrigin::Manual, $status, null, new DateTimeImmutable());

        if (true === $pinned) {
            $row->pin();
        }

        $this->em->persist($row);
        $this->em->flush();
    }

    /**
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function verify(string $raw, MessageCrypto $crypto, string $from, bool $autocrypt = false, ?string $autocryptKey = null): ?array
    {
        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = $from;
        $message->crypto      = $crypto;
        $autocryptKey       ??= true === $autocrypt ? $this->exportedKey : null;

        if (null !== $autocryptKey) {
            $message->headers = ['autocrypt' => 'addr=' . self::SIGNER . '; keydata=' . base64_encode($autocryptKey)];
        }

        static::getContainer()->get(RawMessageResolver::class)->store($message, $raw);
        $this->em->flush();

        return static::getContainer()->get(SignatureVerifier::class)->verify($message);
    }

    private function smimeSigned(string $body): string
    {
        $key  = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Signer', 'emailAddress' => self::SIGNER], $key), null, $key, 1);
        $in   = (string) tempnam(sys_get_temp_dir(), 'smime-in');
        $out  = (string) tempnam(sys_get_temp_dir(), 'smime-out');

        file_put_contents($in, "Content-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n");
        self::assertTrue(openssl_pkcs7_sign($in, $out, $cert, $key, ['From' => self::SIGNER, 'Subject' => 'Signed'], PKCS7_DETACHED));

        $raw = (string) file_get_contents($out);
        @unlink($in);
        @unlink($out);

        return $raw;
    }

    private ?string $exportedKey = null;

    /**
     * A PGP/MIME signed message made with gpg itself.
     *
     * @return array{0: string, 1: string, 2: string} raw, primary fingerprint, armored public key
     */
    private function pgpSigned(string $body): array
    {
        if (false === GnupgHome::available()) {
            self::markTestSkipped('gpg is not installed here.');
        }

        $home = sys_get_temp_dir() . '/sigtest-' . bin2hex(random_bytes(4));
        mkdir($home, 0700);
        $gpg  = static fn (string ...$args): Process => new Process(['gpg', '--homedir', $home, '--batch', '--yes', ...$args]);

        $gpg('--passphrase', '', '--quick-gen-key', 'Signer <' . self::SIGNER . '>', 'ed25519', 'sign', 'never')->mustRun();

        $fingerprint = '';

        foreach (explode("\n", $gpg('--with-colons', '--list-keys')->mustRun()->getOutput()) as $line) {
            if (str_starts_with($line, 'fpr:')) {
                $fingerprint = strtolower(explode(':', $line)[9]);
                break;
            }
        }

        $publicKey         = $gpg('--armor', '--export', self::SIGNER)->mustRun()->getOutput();
        $this->exportedKey = $gpg('--export', self::SIGNER)->mustRun()->getOutput();

        $signed  = "Content-Type: text/plain; charset=utf-8\r\n\r\n{$body}";
        $process = $gpg('--armor', '--detach-sign');
        $process->setInput($signed);
        $signature = $process->mustRun()->getOutput();

        $raw = "From: Signer <" . self::SIGNER . ">\r\nSubject: Signed\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/signed; micalg=pgp-sha512; protocol=\"application/pgp-signature\"; boundary=\"S\"\r\n\r\n"
            . "--S\r\n{$signed}\r\n"
            . "--S\r\nContent-Type: application/pgp-signature; name=\"signature.asc\"\r\n\r\n"
            . str_replace("\n", "\r\n", trim($signature)) . "\r\n"
            . "--S--\r\n";

        (new Process(['rm', '-rf', $home]))->run();

        return [$raw, $fingerprint, $publicKey];
    }
}
