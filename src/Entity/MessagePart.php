<?php

namespace App\Entity;

use App\Repository\MessagePartRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\UX\Turbo\Attribute\Broadcast;

#[ORM\Entity(repositoryClass: MessagePartRepository::class)]
class MessagePart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messageParts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Message $message = null;

    #[ORM\Column(length: 255)]
    private ?string $contentType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentId = null;

    #[ORM\Column(length: 255)]
    private ?string $disposition = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\Column]
    private ?bool $isInline = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    public function setContentId(?string $contentId): static
    {
        $this->contentId = $contentId;

        return $this;
    }

    public function getDisposition(): ?string
    {
        return $this->disposition;
    }

    public function setDisposition(string $disposition): static
    {
        $this->disposition = $disposition;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function setStoragePath(?string $storagePath): static
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function isInline(): ?bool
    {
        return $this->isInline;
    }

    public function setIsInline(bool $isInline): static
    {
        $this->isInline = $isInline;

        return $this;
    }
}
