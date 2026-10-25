<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * A person's public key as the Web Key Directory hands it out: binary, and
 * with no user ID but the one for the address it was asked for.
 *
 * Reduced because a directory entry answers one question — "which key is
 * anna@example.org's?" — and an imported key often carries more: a private
 * address, an old employer, a name the person did not mean to publish under
 * this domain. The draft says as much (a key "SHOULD only contain the user
 * ID" asked for), and gpg-wks-server does the same. `export-minimal` drops
 * third-party certifications along with superseded self-signatures, which no
 * WKD client needs and which only make the answer larger.
 *
 * Through gpg's `--export-filter keep-uid=…` in a throwaway home
 * (GnupgHome). Writing the same in PHP would mean parsing
 * OpenPGP packets and deciding which signature belongs to which user ID, which
 * PublicKeyReader explains is exactly the part not to do by hand.
 *
 * The result is read back with PublicKeyReader before it is served: it must
 * still be a key gpg accepts for that very address, since that is what every
 * client asking for it will check — WkdLookup included.
 *
 * Cached, keyed by the key's armor and the address, so a renewed key gets a
 * new entry the moment it exists and the public route does not start gpg on
 * every request somebody makes. A failure is cached too, briefly, for the
 * same reason.
 */
final readonly class WkdKeyExport
{
    private const int TTL         = 86400;
    private const int FAILURE_TTL = 300;

    public function __construct(
        private PublicKeyReader $reader,
        private CacheInterface  $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The binary key carrying only $email's user ID, or null when gpg cannot
     * make one that still stands for $email.
     */
    public function reduce(string $armored, string $fingerprint, string $email): ?string
    {
        $email = strtolower(trim($email));

        // The address goes into a gpg filter expression, where `&&` and `||`
        // join terms and whitespace ends a value. A valid address may contain
        // `&`, `|` and `>` in its local part; one that does is not worth a
        // quoting scheme gpg does not document.
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || 1 === preg_match('/[\s&|<>"\\\\]/', $email)) {
            return null;
        }

        $cached = $this->cache->get(
            'wkd.served.' . sha1($fingerprint . "\0" . $email . "\0" . $armored),
            function (ItemInterface $item) use ($armored, $fingerprint, $email): string {
                $binary = $this->export($armored, $fingerprint, $email);
                $item->expiresAfter(null === $binary ? self::FAILURE_TTL : self::TTL);

                return null === $binary ? '' : base64_encode($binary);
            },
        );

        return '' === $cached ? null : (string) base64_decode($cached, true);
    }

    private function export(string $armored, string $fingerprint, string $email): ?string
    {
        if (false === GnupgHome::available()) {
            $this->logger->warning('WKD: gpg is not installed, or does not start; key {fingerprint} not published.', ['fingerprint' => $fingerprint]);

            return null;
        }

        $binary = GnupgHome::with(function (GnupgHome $home) use ($armored, $fingerprint, $email): ?string {
            $import = $home->process('--import');
            $import->setInput($armored);
            $import->run();

            if (false === $import->isSuccessful()) {
                $this->logger->warning('WKD: gpg could not import key {fingerprint}: {error}', ['fingerprint' => $fingerprint, 'error' => trim($import->getErrorOutput())]);

                return null;
            }

            // `uid`, not the `mail` property the manual offers for this: gpg
            // 2.4 matches no user ID by `mail` in a keep-uid filter, so every
            // key came out with none. `=~` is a substring match, case-blind,
            // and the angle brackets keep anna@example.org from matching
            // joanna@example.org; the second term is a user ID that is the
            // bare address.
            $filter = sprintf('keep-uid=uid =~ <%s> || uid = %s', $email, $email);
            $export = $home->process('--export-options', 'export-minimal', '--export-filter', $filter, '--export', $fingerprint);
            $export->run();

            if (false === $export->isSuccessful() || '' === $export->getOutput()) {
                $this->logger->warning('WKD: gpg could not export key {fingerprint}: {error}', ['fingerprint' => $fingerprint, 'error' => trim($export->getErrorOutput())]);

                return null;
            }

            return $export->getOutput();
        });

        if (null === $binary) {
            return null;
        }

        foreach ($this->reader->read($binary) as $key) {
            if ($fingerprint === $key->fingerprint && true === $key->usableFor($email)) {
                return $binary;
            }
        }

        $this->logger->warning('WKD: key {fingerprint} has no valid user ID for {email}; not published.', ['fingerprint' => $fingerprint, 'email' => $email]);

        return null;
    }
}
