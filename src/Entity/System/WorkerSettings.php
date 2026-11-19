<?php

declare(strict_types=1);

namespace App\Entity\System;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\System\WorkerSettings\WorkerSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin → Performance: how much of the background work runs at once (#610).
 *
 * One row, the way the other admin singletons are, and for now one setting:
 * how many mail jobs worker-ingest runs side by side, each in a process of its
 * own (see ChildProcessExecutionStrategy).
 *
 * A row and not an environment variable for the reason LogSettings gives: the
 * moment it is wanted is the moment something is slow, and the person looking
 * at the slow queue on the Performance page should be able to change it there,
 * without editing a file on the host and restarting the stack. The worker
 * reads it again every half a minute.
 */
#[ORM\Entity(repositoryClass: WorkerSettingsRepository::class)]
#[ORM\Table(name: 'worker_settings')]
// The singleton guarantee LogSettings and UpdateCheck use: the only check that
// can hold against two concurrent first inserts is the index.
#[ORM\UniqueConstraint(name: 'uniq_worker_settings_singleton', columns: ['singleton'])]
#[ORM\HasLifecycleCallbacks]
class WorkerSettings
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** Always 1; exists so the unique index above has a column to be unique over. */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    /**
     * How many mail jobs run at once, or null for the default.
     *
     * Null rather than the default's value, so an installation that never
     * chose follows a better default when a later release has one.
     */
    #[ORM\Column(nullable: true)]
    public ?int $mailConcurrency = null;
}
