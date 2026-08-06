<?php

declare(strict_types=1);

namespace App\Domain\DTO\Backup;

use App\Domain\Enum\Backup\ConfigBackupSection;
use DateTimeImmutable;

/**
 * Everything a decrypted backup would do to this instance, before any of it is
 * done — and, after applying, the record of what was.
 *
 * One class for both because they are the same list read at two moments, and
 * two classes would invite them to drift: the review would promise one set of
 * changes and the confirmation would report another, which is the failure this
 * whole two-step exists to prevent. `$applied` is the only difference, and the
 * page says which of the two it is showing.
 *
 * `$instance` and `$exportedAt` come out of the envelope rather than from the
 * request. An admin restoring the wrong file usually holds two backups from two
 * machines, and the only thing that tells them apart is what is written inside.
 */
final readonly class ConfigBackupPlan
{
    /**
     * @param list<ConfigBackupPlanItem> $items
     */
    public function __construct(
        public array $items,
        /** The public address of the instance the backup was made from, or null when it had none. */
        public ?string $instance,
        public ?DateTimeImmutable $exportedAt,
        /** False for a review, true once the automatic items have actually been written. */
        public bool $applied = false,
    ) {
    }

    /**
     * @return list<ConfigBackupPlanItem>
     */
    public function automatic(): array
    {
        return array_values(array_filter($this->items, static fn (ConfigBackupPlanItem $item): bool => $item->isAutomatic()));
    }

    /**
     * @return list<ConfigBackupPlanItem>
     */
    public function manual(): array
    {
        return array_values(array_filter($this->items, static fn (ConfigBackupPlanItem $item): bool => false === $item->isAutomatic()));
    }

    /**
     * The manual items grouped the way the page shows them, empty sections
     * dropped.
     *
     * Here rather than in Twig: a template that filters a list twice per
     * section is a template nobody can read, and "which sections have anything
     * to say" is a question about the plan.
     *
     * @return array<string, list<ConfigBackupPlanItem>>
     */
    public function manualBySection(): array
    {
        $grouped = [];

        foreach (ConfigBackupSection::cases() as $section) {
            $items = array_values(array_filter(
                $this->manual(),
                static fn (ConfigBackupPlanItem $item): bool => $section === $item->section,
            ));

            if ([] !== $items) {
                $grouped[$section->value] = $items;
            }
        }

        return $grouped;
    }

    /** Whether applying this plan would leave the instance different from how it is now. */
    public function hasMaterialChanges(): bool
    {
        foreach ($this->automatic() as $item) {
            if (true === $item->change->isMaterial()) {
                return true;
            }
        }

        return false;
    }
}
