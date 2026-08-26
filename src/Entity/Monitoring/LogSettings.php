<?php

declare(strict_types=1);

namespace App\Entity\Monitoring;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\Monitoring\LogSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * How much of what this installation logs is kept where an administrator can
 * read it.
 *
 * WHY THIS IS A ROW AND NOT ONLY AN ENVIRONMENT VARIABLE
 * ─────────────────────────────────────────────────────
 * `APP_DB_LOG_LEVEL` still sets the default and still works. But the moment it
 * is actually needed is the moment it is most awkward to change: something is
 * wrong, the answer is one level further down, and reaching it means editing a
 * file on the host, restarting the stack, and losing whatever was in flight —
 * so the level tends to be turned down, forgotten, and left down.
 *
 * A row can be changed from the page that shows the logs, by the person already
 * looking at them, and turned back afterwards.
 *
 * NULL MEANS "WHATEVER THE ENVIRONMENT SAYS", and that is not the same as
 * storing the env var's current value here. An install that has never touched
 * this keeps following its configuration, including a later change to it; one
 * that has chosen a level keeps that choice across a redeploy. Storing the
 * resolved value would silently freeze the first, and there would be no way
 * back to "follow the environment" once anything had been set.
 */
#[ORM\Entity(repositoryClass: LogSettingsRepository::class)]
#[ORM\Table(name: 'log_settings')]
// The same singleton guarantee FcmConfig uses, for the same reason: every check
// done in PHP happens before the insert, and therefore before another request's
// insert. The index is the only thing that can actually hold it.
#[ORM\UniqueConstraint(name: 'uniq_log_settings_singleton', columns: ['singleton'])]
#[ORM\HasLifecycleCallbacks]
class LogSettings
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Always 1. Never read, never written by anything but this declaration — it
     * exists so the unique index above has a column to be unique over.
     */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    /**
     * The Monolog level NAME — 'info', 'warning' — or null to follow the
     * environment.
     *
     * The name rather than the numeric value, because that is what
     * APP_DB_LOG_LEVEL holds and what the handler's constructor already
     * parses; two spellings of the same setting is one more thing to keep in
     * step for no gain.
     */
    #[ORM\Column(name: 'minimum_level', length: 16, nullable: true)]
    public ?string $minimumLevel = null;
}
