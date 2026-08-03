<?php

declare(strict_types=1);

namespace App\Domain\Trait;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a row appeared and when it last changed.
 *
 * Every entity uses this; none declares a timestamp by hand. A table whose rows
 * are only ever written once still carries updated_at, because a column that
 * mirrors created_at costs nothing and one rule is worth more than the handful
 * of bytes an exception would save.
 *
 * Requires #[ORM\HasLifecycleCallbacks] on the class. Without it Doctrine
 * ignores both callbacks and says nothing: no error, no failure, the timestamps
 * simply never move. TimestampableTest checks every adopting entity for it.
 */
trait TimestampableTrait
{
    /**
     * Neither is nullable and neither has a default, so both are uninitialised
     * until PrePersist runs and set forever after. That models the truth: a row
     * that exists has both, and a reader should not be made to check for a null
     * the database cannot contain. Reading one before the entity is persisted
     * throws, which is the right answer to a genuine mistake.
     */
    #[ORM\Column]
    public private(set) DateTimeImmutable $createdAt;

    #[ORM\Column]
    public private(set) DateTimeImmutable $updatedAt;

    /**
     * createdAt is left alone when already set, so a caller that needs a
     * specific instant — a backfill replaying history, a test placing a row
     * before a retention window — can supply one and keep it. ??= reads through
     * isset(), which answers false for an uninitialised typed property rather
     * than throwing, so this works without the property being nullable.
     */
    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $now = new DateTimeImmutable();

        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    /**
     * Fires only when Doctrine sees a mapped field actually change, so a flush
     * that changes nothing leaves the column alone. That is deliberate, and a
     * difference from the manual writes this replaced, which stamped the row
     * whether or not anything had happened to it.
     */
    #[ORM\PreUpdate]
    public function bumpUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
