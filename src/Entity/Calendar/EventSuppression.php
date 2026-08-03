<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Entity\User\User;
use App\Repository\Calendar\EventSuppressionRepository;
use DateTimeImmutable;
use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Not an event" — a user's refusal, remembered.
 *
 * Small, and easy to leave until later, and the difference between a feature
 * people like and one that feels like it is fighting them. Extraction is
 * re-runnable by design: a backfill can walk the whole mailbox again after a
 * mapper improves. Without this table, every one of those runs would put back
 * the thing the user just dismissed.
 *
 * Keyed on the dedup key's hash rather than the event id, because the point is
 * to survive the event being deleted — and to catch the *next* message about
 * the same booking before it creates one.
 */
#[ORM\Entity(repositoryClass: EventSuppressionRepository::class)]
#[ORM\Table(name: 'event_suppression')]
#[ORM\UniqueConstraint(name: 'uniq_event_suppression', columns: ['usr_id', 'dedup_key_hash'])]
#[ORM\HasLifecycleCallbacks]
class EventSuppression
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $usr = null;

    /** sha256 of the dedup key, hex. Fixed width, and nothing needs the original. */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    public string $dedupKeyHash = '';


    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }
}
