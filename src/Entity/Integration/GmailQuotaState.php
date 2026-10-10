<?php

declare(strict_types=1);
namespace App\Entity\Integration;
use Doctrine\ORM\Mapping as ORM;

/** DBAL owns atomic updates; mapping keeps schema tooling aware of shared state. */
#[ORM\Entity]
#[ORM\Table(name: 'gmail_quota_state')]
class GmailQuotaState
{
    #[ORM\Id, ORM\Column(length: 64)]
    public string $quotaKey;
    #[ORM\Column(type: 'float')]
    public float $credits = 1000;
    #[ORM\Column(type: 'float')]
    public float $updatedAt = 0;
    #[ORM\Column(type: 'float')]
    public float $blockedUntil = 0;
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $warning = null;
    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    public array $pendingIds = [];
}
