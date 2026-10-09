<?php

declare(strict_types=1);

namespace App\Entity\Demo;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\Demo\DemoVisitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One demo session handed out, for Admin → Demo visitors.
 *
 * A row of its own rather than a count over the users, because the users do not
 * last: app:demo:reap deletes a visitor two hours after they arrive, and with
 * them every trace that they came. "How many this week" has to be written down
 * somewhere the reaper does not go.
 *
 * It holds no address. `visitorHash` is what DemoVisitorFingerprint makes of
 * one — see there for what it can and cannot be turned back into — and it is
 * here only so that the same visitor coming back counts once. It is emptied
 * after DemoVisitRepository::HASH_RETENTION_DAYS, which leaves the row counting
 * a visit and nothing more.
 *
 * Written only by DemoController::start(), which does not exist outside demo
 * mode, so on a normal install this table is created and stays empty.
 */
#[ORM\Entity(repositoryClass: DemoVisitRepository::class)]
#[ORM\Table(name: 'demo_visit')]
#[ORM\Index(name: 'idx_demo_visit_created_at', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class DemoVisit
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * A keyed hash of the visitor's network, or null: when the request carried
     * no address, and on every row old enough to have had it taken away.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $visitorHash = null;

    public function __construct(?string $visitorHash = null)
    {
        $this->visitorHash = $visitorHash;
    }
}
