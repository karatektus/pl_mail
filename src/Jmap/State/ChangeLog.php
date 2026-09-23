<?php

declare(strict_types=1);

namespace App\Jmap\State;

use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only log of object mutations. The autoincrement primary key is the
 * monotonic state token: a client's "state" for a given account+objectType is
 * the highest sequence recorded for it, and "/changes" simply returns rows
 * with sequence > sinceState.
 *
 * That only holds if a later number never commits before an earlier one of the
 * same account, and an identity value is drawn at INSERT, not COMMIT. A BEFORE
 * INSERT trigger (Version20260923140100) takes a per-account advisory lock and
 * then draws the number, so one account's writers queue until commit.
 *
 * accountId is stored as a scalar (not a ManyToOne) on purpose: these rows are
 * written from long-running sync handlers where holding entity references
 * across flush() is the documented footgun. A plain id sidesteps it entirely.
 */
#[ORM\Entity(repositoryClass: ChangeLogRepository::class)]
#[ORM\Table(name: 'jmap_change_log')]
#[ORM\Index(name: 'idx_jmap_change_scan', columns: ['account_id', 'object_type', 'sequence'])]
#[ORM\HasLifecycleCallbacks]
class ChangeLog
{
    use TimestampableTrait;

    // integer (32-bit). app:jmap:prune-changes keeps the table to a window
    // (see MaintenanceSchedule), which bounds its size but not the sequence:
    // numbers are never reused, so 2.1 billion is still the ceiling on how many
    // changes an install can ever record. That is a long way off at mailbox
    // rates. When it matters, switching to type: 'bigint' means retyping the
    // property to ?string too (Doctrine hydrates bigint as a string).
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $sequence = null;

    #[ORM\Column(name: 'account_id')]
    public private(set) int $accountId;

    #[ORM\Column(name: 'object_type', length: 32, enumType: JmapObjectType::class)]
    public private(set) JmapObjectType $objectType;

    #[ORM\Column(name: 'entity_id', length: 64)]
    public private(set) string $entityId;

    #[ORM\Column(name: 'change_type', length: 16, enumType: ChangeType::class)]
    public private(set) ChangeType $changeType;


    public function __construct(
        int $accountId,
        JmapObjectType $objectType,
        string $entityId,
        ChangeType $changeType,
    ) {
        $this->accountId = $accountId;
        $this->objectType = $objectType;
        $this->entityId = $entityId;
        $this->changeType = $changeType;
        $this->createdAt = new \DateTimeImmutable();
    }
}
