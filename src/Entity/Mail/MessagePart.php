<?php

namespace App\Entity\Mail;

use App\Repository\Mail\MessagePartRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\UX\Turbo\Attribute\Broadcast;

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
}
