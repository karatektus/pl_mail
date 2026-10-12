<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\SignatureState;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Crypto\SignatureVerifier;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
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
 * than the sender is the forgery to flag.
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

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function verify(string $raw, MessageCrypto $crypto, string $from, bool $autocrypt = false): ?array
    {
        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = $from;
        $message->crypto      = $crypto;

        if (true === $autocrypt && null !== $this->exportedKey) {
            $message->headers = ['autocrypt' => 'addr=' . self::SIGNER . '; keydata=' . base64_encode($this->exportedKey)];
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
        if (false === class_exists(\gnupg::class)) {
            self::markTestSkipped('ext-gnupg is not installed here.');
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
