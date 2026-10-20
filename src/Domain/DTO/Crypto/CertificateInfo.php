<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

use DateTimeImmutable;

/**
 * One X.509 certificate as OpenSSL read it: what plMail needs to decide
 * whether an S/MIME certificate may stand for an address, and the PEM to keep.
 *
 * The S/MIME counterpart of PublicKeyInfo. Read by OpenSSL rather than parsed
 * here (App\Service\Crypto\CertificateReader), so "the certificate is for this
 * address" means what every mail program means by it: a subjectAltName email,
 * or the older emailAddress in the subject.
 */
final readonly class CertificateInfo
{
    /**
     * @param list<string> $addresses lower-cased, subjectAltName first
     */
    public function __construct(
        public string            $pem,
        public string            $fingerprint,
        public array             $addresses,
        public string            $subject,
        public string            $issuer,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $notAfter,
        public bool              $selfSigned,
        public bool              $rsa,
        public bool              $canEncrypt,
    ) {
    }

    public function isValidAt(DateTimeImmutable $moment): bool
    {
        return $this->notBefore <= $moment && $moment < $this->notAfter;
    }

    /**
     * Whether mail to $email may be encrypted to this certificate: valid now,
     * for that address, with an RSA key that is allowed to carry a content
     * key — RSA because key transport is what plMail encrypts with
     * (assets/crypto/smime.js), and what Outlook and Thunderbird expect.
     */
    public function usableFor(string $email, DateTimeImmutable $moment): bool
    {
        return true === $this->isValidAt($moment)
            && true === $this->rsa
            && true === $this->canEncrypt
            && true === in_array(strtolower(trim($email)), $this->addresses, true);
    }
}
