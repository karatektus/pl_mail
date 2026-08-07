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
 * request. An operator restoring the wrong file usually holds two backups from
 * two machines, and the only thing that tells them apart is what is written
 * inside.
 *
 * The three readers below are the shape of the page, and they are here rather
 * than in Twig because "which of these asks something of the operator" is a
 * question about the plan:
 *
 *   - {@see self::written()} — what plMail puts in place itself;
 *   - {@see self::instructed()} — the remainder, which after this rework should
 *     be empty on a supported deployment and is the number the feature is
 *     judged by;
 *   - {@see self::notes()} — worth stating, asks nothing.
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
        /** False for a review, true once the plan has actually been executed. */
        public bool $applied = false,
    ) {
    }

    /**
     * What plMail writes itself and nothing further is asked about.
     *
     * A shadowed value is written too, but it is not here: it belongs in the
     * list of things to do, because writing it is not enough to make it true.
     *
     * @return list<ConfigBackupPlanItem>
     */
    public function written(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ConfigBackupPlanItem $item): bool => $item->isWritten() && false === $item->needsOperator(),
        ));
    }

    /**
     * The residue: everything that needs a human.
     *
     * @return list<ConfigBackupPlanItem>
     */
    public function instructed(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ConfigBackupPlanItem $item): bool => $item->needsOperator(),
        ));
    }

    /**
     * Stated, not asked. APP_ENCRYPTION_KEY, which is kept on purpose.
     *
     * @return list<ConfigBackupPlanItem>
     */
    public function notes(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ConfigBackupPlanItem $item): bool => $item->disposition->isNote(),
        ));
    }

    /**
     * The instructed items grouped the way the page shows them, empty sections
     * dropped.
     *
     * @return array<string, list<ConfigBackupPlanItem>>
     */
    public function instructedBySection(): array
    {
        $grouped = [];

        foreach (ConfigBackupSection::cases() as $section) {
            $items = array_values(array_filter(
                $this->instructed(),
                static fn (ConfigBackupPlanItem $item): bool => $section === $item->section,
            ));

            if ([] !== $items) {
                $grouped[$section->value] = $items;
            }
        }

        return $grouped;
    }

    /**
     * Whether anything in this plan only becomes real at the next container
     * start.
     *
     * Asked once for the whole plan rather than per value, which is the entire
     * point: the old page printed a restart instruction beside every single
     * environment variable, and an operator reading two dozen of them cannot
     * tell that they are one instruction. Unchanged items do not count — a plan
     * that writes nothing new has no reason to send anybody to a terminal.
     */
    public function needsRestart(): bool
    {
        foreach ($this->items as $item) {
            if (true === $item->change->isMaterial() && true === $item->disposition->needsRestart()) {
                return true;
            }
        }

        return false;
    }

    /** Whether applying this plan would leave the instance different from how it is now. */
    public function hasMaterialChanges(): bool
    {
        foreach ($this->items as $item) {
            if (true === $item->isWritten() && true === $item->change->isMaterial()) {
                return true;
            }
        }

        return false;
    }
}
