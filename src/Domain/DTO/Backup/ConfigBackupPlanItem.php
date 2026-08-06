<?php

declare(strict_types=1);

namespace App\Domain\DTO\Backup;

use App\Domain\Enum\Backup\ConfigBackupChange;
use App\Domain\Enum\Backup\ConfigBackupObstacle;
use App\Domain\Enum\Backup\ConfigBackupSection;

/**
 * One line of the import review: a thing the backup carries, where it goes, and
 * whether plMail is going to put it there or tell the admin to.
 *
 * A DTO rather than an array because it crosses two boundaries — the importer
 * builds it and both the admin page and the setup page render it — and because
 * `$obstacle === null` is the single test for "we will do this ourselves". An
 * array would let a caller half-answer that.
 *
 * `$instruction` carries the SECRET for an environment value, deliberately: an
 * admin who is told "set APP_SECRET yourself" and not told to what has been
 * given a chore, not an instruction. They are reading their own backup on their
 * own admin page, having just typed the password that opened it. For a file
 * the instruction is a path only — a PEM pasted into a review page is not
 * copy-pasteable in any useful sense, and the backup file the admin is holding
 * is where the bytes already are.
 */
final readonly class ConfigBackupPlanItem
{
    public function __construct(
        public ConfigBackupSection $section,
        /** The env variable name, the file's path within the secrets directory, or the table's label. */
        public string $key,
        public ConfigBackupChange $change,
        /** Null when plMail applies this itself. */
        public ?ConfigBackupObstacle $obstacle = null,
        /** Exactly what to paste or where to put it; null when nothing is asked of the admin. */
        public ?string $instruction = null,
    ) {
    }

    public function isAutomatic(): bool
    {
        return null === $this->obstacle;
    }
}
