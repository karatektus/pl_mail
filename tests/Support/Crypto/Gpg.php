<?php

declare(strict_types=1);

namespace App\Tests\Support\Crypto;

use App\Service\Crypto\GnupgHome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Real OpenPGP keys and signatures for tests, made by the gpg binary.
 *
 * Real rather than fixtures checked in: what plMail decides about a key —
 * which user IDs gpg accepts, whether it is expired or revoked — is gpg's
 * judgement, and a key frozen in a file would freeze that judgement with it
 * (an expiry date in a fixture is a test that fails on a date). One home per
 * key, removed with the object.
 */
final class Gpg
{
    public readonly string $fingerprint;
    public readonly string $armored;
    public readonly string $binary;

    private string $home;

    /**
     * @param list<string> $extraAddresses further user IDs on the same key
     * @param bool         $expired        made in 2020 to last a year, so long expired
     */
    public function __construct(
        public readonly string $address,
        array $extraAddresses = [],
        bool $expired = false,
    ) {
        if (false === GnupgHome::available()) {
            TestCase::markTestSkipped('gpg is not installed here.');
        }

        $this->home = sys_get_temp_dir() . '/gpgtest-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0700);

        $past = true === $expired ? ['--faked-system-time', '20200101T000000'] : [];

        $this->run(...$past, ...['--passphrase', '', '--quick-gen-key', 'Test <' . $address . '>', 'ed25519', 'sign', true === $expired ? '1y' : 'never']);

        $fingerprint = '';

        foreach ($this->colons() as $line) {
            if (str_starts_with($line, 'fpr:')) {
                $fingerprint = strtolower(explode(':', $line)[9]);
                break;
            }
        }

        $this->fingerprint = $fingerprint;

        $this->run(...$past, ...['--passphrase', '', '--quick-add-key', $fingerprint, 'cv25519', 'encr', true === $expired ? '1y' : 'never']);

        foreach ($extraAddresses as $extra) {
            $this->run('--passphrase', '', '--quick-add-uid', $fingerprint, 'Test <' . $extra . '>');
        }

        $this->armored = $this->run('--armor', '--export', $fingerprint);
        $this->binary  = $this->run('--export', $fingerprint);
    }

    public function __destruct()
    {
        new Process(['rm', '-rf', $this->home])->run();
    }

    /** The key with its revocation applied, armored. */
    public function revoked(): string
    {
        // gpg writes a revocation certificate when it makes a key, with a
        // colon in front of the armor so nobody imports it by accident.
        $certificate = (string) file_get_contents($this->home . '/openpgp-revocs.d/' . strtoupper($this->fingerprint) . '.rev');
        $this->run('--import', input: str_replace(':-----BEGIN', '-----BEGIN', $certificate));

        return $this->run('--armor', '--export', $this->fingerprint);
    }

    /**
     * A PGP/MIME signed message from $from, signed by this key.
     */
    public function signedMessage(string $body, ?string $from = null, array $headers = []): string
    {
        $signed  = "Content-Type: text/plain; charset=utf-8\r\n\r\n{$body}";
        $signature = $this->run('--armor', '--detach-sign', '--local-user', $this->fingerprint, input: $signed);
        $extra   = '';

        foreach ($headers as $name => $value) {
            $extra .= "{$name}: {$value}\r\n";
        }

        return 'From: Signer <' . ($from ?? $this->address) . ">\r\nSubject: Signed\r\nMIME-Version: 1.0\r\n" . $extra
            . "Content-Type: multipart/signed; micalg=pgp-sha512; protocol=\"application/pgp-signature\"; boundary=\"S\"\r\n\r\n"
            . "--S\r\n{$signed}\r\n"
            . "--S\r\nContent-Type: application/pgp-signature; name=\"signature.asc\"\r\n\r\n"
            . str_replace("\n", "\r\n", trim($signature)) . "\r\n"
            . "--S--\r\n";
    }

    /**
     * The key as keys.openpgp.org hands out one whose owner never confirmed
     * an address: the key material without a single user ID. Armored.
     */
    public function withoutUserIds(): string
    {
        return $this->run('--armor', '--export-options', 'export-minimal', '--export-filter', 'keep-uid=uid =~ no-such-user-id', '--export', $this->fingerprint);
    }

    /** $body as an inline (cleartext) signed message, signed by this key. */
    public function clearSigned(string $body): string
    {
        return $this->run('--clearsign', '--local-user', $this->fingerprint, input: $body);
    }

    /** @return list<string> */
    private function colons(): array
    {
        return explode("\n", $this->run('--with-colons', '--list-keys'));
    }

    private function run(string ...$args): string
    {
        $input = null;

        if (true === array_key_exists('input', $args)) {
            $input = $args['input'];
            unset($args['input']);
        }

        $process = new Process(['gpg', '--homedir', $this->home, '--batch', '--yes', ...array_values($args)]);
        $process->setInput($input);

        return $process->mustRun()->getOutput();
    }
}
