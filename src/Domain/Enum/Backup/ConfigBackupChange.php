<?php

declare(strict_types=1);

namespace App\Domain\Enum\Backup;

/**
 * What the backup would do to one value, compared with what is there now.
 *
 * Three states rather than "will change: yes/no", because the middle one is the
 * one an admin needs to see before they agree to anything. Restoring onto a
 * fresh instance is all Absent and reads as obviously safe; restoring onto an
 * instance that is already running is a list of Differs, and each of those is a
 * credential being replaced by another one. An import that showed a single
 * count would hide exactly the case worth stopping at.
 *
 * Unchanged is kept and shown rather than filtered out, so the review is a
 * complete account of the file. A row missing from a list of "what will happen"
 * is indistinguishable from a value the backup did not carry.
 */
enum ConfigBackupChange: string
{
    /** Nothing is configured here now; the backup supplies it. */
    case Absent = 'absent';

    /** Something is configured, and it is not what the backup carries. */
    case Differs = 'differs';

    /** Already exactly what the backup carries. */
    case Unchanged = 'unchanged';

    public function transKey(): string
    {
        return 'admin.config_backup.change.' . $this->value;
    }

    /** Whether applying this would actually alter the instance. */
    public function isMaterial(): bool
    {
        return match ($this) {
            self::Absent, self::Differs => true,
            self::Unchanged             => false,
        };
    }
}
