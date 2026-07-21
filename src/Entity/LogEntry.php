<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LogEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Monolog record persisted by DoctrineLogHandler so all containers'
 * warnings/errors aggregate in one queryable place. Written via DBAL —
 * the ORM side only reads. Pruned by app:monitoring:prune.
 */
#[ORM\Entity(repositoryClass: LogEntryRepository::class)]
#[ORM\Index(name: 'idx_log_entry_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_log_entry_level', columns: ['level'])]
#[ORM\Index(name: 'idx_log_entry_channel', columns: ['channel'])]
class LogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 64)]
    public string $channel = '';

    /** Monolog numeric level (300 = warning, 400 = error, …). */
    #[ORM\Column]
    public int $level = 0;

    #[ORM\Column(length: 32)]
    public string $levelName = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $message = '';

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $context = null;

    /** Which container produced this (APP_CONTAINER_NAME). */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $source = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;
}
