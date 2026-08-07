<?php

declare(strict_types=1);

namespace App\Domain\DTO\Backup;

use App\Domain\Enum\Backup\ConfigBackupChange;
use App\Domain\Enum\Backup\ConfigBackupDisposition;
use App\Domain\Enum\Backup\ConfigBackupSection;

/**
 * One line of the import review: a thing the backup carries, where it goes, and
 * what becomes of it.
 *
 * A DTO rather than an array because it crosses two boundaries — the importer
 * builds it and both the admin page and the setup page render it — and because
 * `$disposition` is the single word for what happens to this value. An array
 * would let a caller half-answer that.
 *
 * `$instruction` carries the SECRET for an environment value, deliberately: an
 * operator who is told "this one is pinned in your compose file" and not told
 * to what has been given a chore, not an instruction. They are reading their
 * own backup on their own admin page, having just typed the password that
 * opened it. For a file the instruction is a path only — a PEM pasted into a
 * review page is not copy-pasteable in any useful sense, and the backup file
 * the operator is holding is where the bytes already are.
 *
 * It is null for everything plMail applied by itself, which after this rework
 * is nearly all of it.
 */
final readonly class ConfigBackupPlanItem
{
    public function __construct(
        public ConfigBackupSection $section,
        /** The env variable name, the file's path within the secrets directory, or the table's label. */
        public string $key,
        public ConfigBackupChange $change,
        public ConfigBackupDisposition $disposition,
        /** Exactly what to paste or where to put it; null when nothing is asked of the operator. */
        public ?string $instruction = null,
    ) {
    }

    /** Whether the import writes this value itself. */
    public function isWritten(): bool
    {
        return $this->disposition->isWritten();
    }

    /** Whether the operator has to go and do something about this one. */
    public function needsOperator(): bool
    {
        return $this->disposition->needsOperator();
    }
}
