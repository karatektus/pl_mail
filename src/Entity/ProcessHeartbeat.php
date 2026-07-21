<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProcessHeartbeatRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per monitored long-running process (IMAP IDLE per mailbox, the
 * IDLE supervisor, each messenger worker). Written exclusively via DBAL
 * upsert in ProcessHeartbeatService — the ORM side only reads.
 */
#[ORM\Entity(repositoryClass: ProcessHeartbeatRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_heartbeat_type_key', columns: ['type', 'beat_key'])]
class ProcessHeartbeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** One of the ProcessHeartbeatService::TYPE_* constants. */
    #[ORM\Column(length: 64)]
    public string $type = '';

    /** Instance discriminator: mailbox id, hostname, or 'main'. */
    #[ORM\Column(name: 'beat_key', length: 128)]
    public string $key = '';

    #[ORM\Column(nullable: true)]
    public ?int $pid = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $lastBeatAt = null;

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $meta = null;
}
