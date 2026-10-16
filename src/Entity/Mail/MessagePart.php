<?php

namespace App\Entity\Mail;

use App\Repository\Mail\MessagePartRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessagePartRepository::class)]
class MessagePart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messageParts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Message $message = null;

    #[ORM\Column(length: 255)]
    public ?string $contentType = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $contentId = null;

    #[ORM\Column(length: 255)]
    public ?string $disposition = null;

    #[ORM\Column(nullable: true)]
    public ?int $size = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storagePath = null;

    #[ORM\Column]
    public ?bool $isInline = false;

    /**
     * Uploaded to a sealed draft, so encrypted in the browser to the person's
     * own key: the stored bytes are an OpenPGP message, and contentType and
     * filename are what the browser said the file was. Nothing on the server
     * reads such a part — no thumbnail, no preview, no text extraction — and
     * it never goes out as it is: the browser opens it again when it builds
     * the message. See App\Service\Crypto\SealedDrafts.
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $sealed = false;

    /**
     * The fingerprint of the key this part holds, when it is the sender's own
     * public key — one with a user ID for the From address, or one plMail
     * already holds for it (phase 6g of #28). Such a part is not listed as
     * an attachment and does not count towards hasAttachments: the crypto
     * banner says "The sender's key is attached" instead. A key that is
     * somebody else's (a forwarded colleague's) stays null, and stays an
     * attachment. Set by ContactKeyCollector::markAttachedKeys().
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $senderKeyFingerprint = null;

    /** Whether markAttachedKeys() has looked at this part, so it is not opened twice. */
    #[ORM\Column(options: ['default' => false])]
    public bool $keyChecked = false;

    /** Whether this part is shown as an attachment. */
    public function isListed(): bool
    {
        return true !== $this->isInline && null === $this->senderKeyFingerprint;
    }
}
