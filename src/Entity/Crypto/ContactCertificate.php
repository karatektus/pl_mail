<?php

declare(strict_types=1);

namespace App\Entity\Crypto;

use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Crypto\ContactCertificateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * An S/MIME certificate plMail knows for somebody a person writes with.
 *
 * The S/MIME counterpart of ContactKey, and a table of its own rather than a
 * kind of row in that one. Almost nothing about the two is shared: a key is
 * armored OpenPGP that gpg reads, comes from WKD, Autocrypt and attachments,
 * and carries the recipient's encrypt/sign/scheme preferences; a certificate
 * is X.509 that OpenSSL reads, comes only with a signature, and may be vouched
 * for by an authority. A `type` column would have made every one of those
 * columns half-empty and every reader of the table check which half it has.
 *
 * What is shared is the shape of the decision, and so the status: one Current
 * certificate per address, others waiting as Changed (ContactKeyStatus). Who
 * decides is different — see App\Service\Crypto\ContactCertificates.
 */
#[ORM\Entity(repositoryClass: ContactCertificateRepository::class)]
#[ORM\Table(name: 'contact_certificate')]
#[ORM\UniqueConstraint(name: 'uniq_contact_certificate_address_fingerprint', columns: ['usr_id', 'email', 'fingerprint'])]
// At most one certificate in use per address, for the reason ContactKey gives
// for its own index of the same shape.
#[ORM\UniqueConstraint(name: 'uniq_contact_certificate_current', columns: ['usr_id', 'email'], options: ['where' => "status = 'current'"])]
#[ORM\HasLifecycleCallbacks]
class ContactCertificate
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Lower-case, trimmed: the form every lookup compares against. */
    #[ORM\Column(length: 255)]
    public private(set) string $email;

    /** SHA-256 of the certificate's DER, lower-case hex. */
    #[ORM\Column(length: 64)]
    public private(set) string $fingerprint;

    /** PEM: what mail to this address is encrypted to. */
    #[ORM\Column(type: 'text')]
    public private(set) string $certificate;

    /**
     * It chained to an authority the system trusts when it was last seen.
     * That, and not a person's confirmation, is what makes an S/MIME signature
     * Valid: it is what the certificate is for. A certificate nobody vouches
     * for — self-signed, a private CA — is trusted on first use, like a key.
     */
    #[ORM\Column(options: ['default' => false])]
    public private(set) bool $verified;

    #[ORM\Column(length: 16, enumType: ContactKeyStatus::class)]
    public private(set) ContactKeyStatus $status;

    #[ORM\Column(length: 255)]
    public private(set) string $subject;

    #[ORM\Column(length: 255)]
    public private(set) string $issuer;

    #[ORM\Column]
    public private(set) DateTimeImmutable $notBefore;

    #[ORM\Column]
    public private(set) DateTimeImmutable $notAfter;

    #[ORM\Column]
    public private(set) DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    public private(set) DateTimeImmutable $lastSeenAt;

    public function __construct(
        User $usr,
        string $email,
        string $fingerprint,
        string $certificate,
        bool $verified,
        ContactKeyStatus $status,
        string $subject,
        string $issuer,
        DateTimeImmutable $notBefore,
        DateTimeImmutable $notAfter,
        DateTimeImmutable $seenAt,
    ) {
        $this->usr         = $usr;
        $this->email       = strtolower(trim($email));
        $this->fingerprint = strtolower($fingerprint);
        $this->certificate = $certificate;
        $this->verified    = $verified;
        $this->status      = $status;
        $this->subject     = $subject;
        $this->issuer      = $issuer;
        $this->notBefore   = $notBefore;
        $this->notAfter    = $notAfter;
        $this->firstSeenAt = $seenAt;
        $this->lastSeenAt  = $seenAt;
    }

    public function isCurrent(): bool
    {
        return ContactKeyStatus::Current === $this->status;
    }

    public function isExpiredAt(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->notAfter;
    }

    /**
     * Seen again on a signature. Once vouched for, it stays so: an authority
     * that vouched for it then still did, and the system bundle changing
     * under a running install is not the certificate's doing.
     */
    public function seenAgain(bool $verified, DateTimeImmutable $at): void
    {
        $this->verified   = $this->verified || $verified;
        $this->lastSeenAt = $at;
    }

    /** In use for its address from now on. */
    public function makeCurrent(): void
    {
        $this->status = ContactKeyStatus::Current;
    }

    /** As a config backup carried it. */
    public function restoreSeen(DateTimeImmutable $firstSeenAt, DateTimeImmutable $lastSeenAt): void
    {
        $this->firstSeenAt = $firstSeenAt;
        $this->lastSeenAt  = $lastSeenAt;
    }
}
