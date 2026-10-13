<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Domain\Enum\Crypto\SmimeCertificateStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Crypto\SmimeCertificateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of a person's own S/MIME certificates, with its private key wrapped
 * under their user key.
 *
 * Imported from a PKCS#12 file in the browser (assets/crypto/smime.js), which
 * opens the file, wraps the private key and sends the rest. The server never
 * sees the key unwrapped, nor the file's password: it gets the PKCS#8 key
 * sealed with AES-256-GCM under the user key, purpose `plmail/smime-key`, the
 * same wrapping that holds an address key's passphrase.
 *
 * Wrapped directly under the user key rather than locked with a passphrase
 * that is wrapped in turn, as an address key is. The passphrase is there for
 * OpenPGP's sake — an armored private key carries its own protection — and a
 * PKCS#8 key has nothing of the kind; a second layer would protect nothing the
 * first does not.
 *
 * The addresses are the certificate's own (subjectAltName, emailAddress) that
 * the person owns. A certificate is for the addresses it names: binding it to
 * another would sign mail recipients' programs refuse as "not from the
 * signer".
 */
#[ORM\Entity(repositoryClass: SmimeCertificateRepository::class)]
#[ORM\Table(name: 'smime_certificate')]
#[ORM\UniqueConstraint(name: 'uniq_smime_certificate_fingerprint', columns: ['usr_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_smime_certificate_user_key', columns: ['user_key_id'])]
#[ORM\HasLifecycleCallbacks]
class SmimeCertificate
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** The user key whose wrapping of $privateKey opens it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_key_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) UserKey $userKey;

    /** SHA-256 of the certificate's DER, lower-case hex: what a seal names it by. */
    #[ORM\Column(length: 64)]
    public private(set) string $fingerprint;

    /** The certificate itself, PEM. What recipients get with every signature. */
    #[ORM\Column(type: 'text')]
    public private(set) string $certificate;

    /**
     * The issuing certificates the PKCS#12 file carried, PEM, possibly none.
     * Sent along with signatures, so a recipient can build the chain to an
     * authority they trust, and put back into the file on export.
     */
    #[ORM\Column(type: 'text')]
    public private(set) string $chain;

    /** PKCS#8, wrapped under $userKey. See App\Security\Crypto\Wrapped for the shape. */
    #[ORM\Column(type: 'text')]
    public private(set) string $privateKey;

    /** @var list<string> lower-cased */
    #[ORM\Column(type: 'json')]
    public private(set) array $addresses;

    /** The certificate's subject, for a person to recognise it by. */
    #[ORM\Column(length: 255)]
    public private(set) string $subject;

    #[ORM\Column(length: 255)]
    public private(set) string $issuer;

    #[ORM\Column]
    public private(set) DateTimeImmutable $notBefore;

    #[ORM\Column]
    public private(set) DateTimeImmutable $notAfter;

    #[ORM\Column(length: 16, enumType: SmimeCertificateStatus::class)]
    public private(set) SmimeCertificateStatus $status = SmimeCertificateStatus::Active;

    /**
     * @param list<string> $addresses
     */
    public function __construct(
        User $usr,
        UserKey $userKey,
        string $fingerprint,
        string $certificate,
        string $chain,
        string $privateKey,
        array $addresses,
        string $subject,
        string $issuer,
        DateTimeImmutable $notBefore,
        DateTimeImmutable $notAfter,
    ) {
        $this->usr         = $usr;
        $this->userKey     = $userKey;
        $this->fingerprint = strtolower($fingerprint);
        $this->certificate = $certificate;
        $this->chain       = $chain;
        $this->privateKey  = $privateKey;
        $this->addresses   = array_values(array_unique(array_map(static fn (string $address): string => strtolower(trim($address)), $addresses)));
        $this->subject     = $subject;
        $this->issuer      = $issuer;
        $this->notBefore   = $notBefore;
        $this->notAfter    = $notAfter;
    }

    public function isActive(): bool
    {
        return SmimeCertificateStatus::Active === $this->status;
    }

    /**
     * Whether mail from or to $email may use this certificate at $moment:
     * active, for that address, and inside its validity. An expired
     * certificate still decrypts — that is what keeping it is for — but
     * neither signs nor is encrypted to.
     */
    public function servesAt(string $email, DateTimeImmutable $moment): bool
    {
        return true === $this->isActive()
            && true === in_array(strtolower(trim($email)), $this->addresses, true)
            && $this->notBefore <= $moment
            && $moment < $this->notAfter;
    }

    public function retire(): void
    {
        $this->status = SmimeCertificateStatus::Retired;
    }

    /** As a config backup carried it. */
    public function restoreStatus(SmimeCertificateStatus $status): void
    {
        $this->status = $status;
    }
}
