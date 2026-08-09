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
 * The readers below are the shape of the page, and they are here rather than in
 * Twig because "which of these asks something of the operator" is a question
 * about the plan:
 *
 *   - {@see self::written()} — what plMail puts in place itself;
 *   - {@see self::chores()} — the remainder that is genuinely work, which after
 *     this rework should be empty on a supported deployment and is the number
 *     the feature is judged by. Not the same as {@see self::instructed()}: a
 *     value already identical to the live one is not a task, and the difference
 *     between the two is {@see self::alreadyMatching()};
 *   - {@see self::notes()} — worth stating, asks nothing. Split for rendering
 *     into {@see self::encryptionKeyNote()} and {@see self::keptUsers()}.
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
     * Stated, not asked: everything kept on purpose.
     *
     * Two kinds now, and the page shows them apart because they read
     * differently — see {@see self::encryptionKeyNote()} and
     * {@see self::keptUsers()}. This stays as the complete set, which is what
     * "did the import decline to write anything, and was that right" is asked
     * of.
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
     * The APP_ENCRYPTION_KEY note, or null when the file carried no key.
     *
     * Singled out because the page collapses it to one line. It is addressed to
     * one reader — the operator who is *also* restoring the old database, and
     * needs its old key in their hand — and it was being shown, expanded, with
     * a base64 secret in a code block, to everyone who will never need it. The
     * information cannot be dropped: restoring a database without its key means
     * every encrypted row in it is gone. So it is kept, reachable, and quiet.
     */
    public function encryptionKeyNote(): ?ConfigBackupPlanItem
    {
        foreach ($this->notes() as $item) {
            if (ConfigBackupSection::Environment === $item->section) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The people the file carries that this install already had, left exactly
     * as they are.
     *
     * Its own list rather than a line in the notes, because on the page that
     * matters — an admin importing onto a running install — this is the answer
     * to the question they should be asking: *what did this do to my users?*
     * "Nothing" is a good answer and it has to be legible as one, per person,
     * by name.
     *
     * @return list<ConfigBackupPlanItem>
     */
    public function keptUsers(): array
    {
        return array_values(array_filter(
            $this->notes(),
            static fn (ConfigBackupPlanItem $item): bool => ConfigBackupSection::Users === $item->section,
        ));
    }

    /**
     * The people this import created, in the order the file listed them.
     *
     * @return list<ConfigBackupPlanItem>
     */
    public function restoredUsers(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ConfigBackupPlanItem $item): bool => ConfigBackupSection::Users === $item->section
                && $item->isWritten()
                && $item->change->isMaterial(),
        ));
    }

    /**
     * The residue that is genuinely work: everything needing a human, minus the
     * ones whose restored value is already exactly what this environment has.
     *
     * **A value identical to the live one is not a task, whoever is nominally
     * responsible for it.** The heading over this list says "these are yours to
     * do", and a row asking an operator to put `MAILER_DSN=null://null` into a
     * compose file that already says `MAILER_DSN=null://null` is not a thing to
     * do — it is a thing already done, printed in the imperative. Two of those
     * appeared on every stock restore, and a list that opens with two
     * non-tasks teaches the reader that the list can be skipped, which is
     * expensive on the day it holds a real one.
     *
     * This is not a retreat from {@see ConfigBackupChange}'s "Unchanged is kept
     * and shown rather than filtered out, so the review is a complete account
     * of the file". The complete account is {@see self::written()} and
     * {@see self::items}, both untouched; what is filtered here is the *chore
     * list*, and a chore that is already done is not an omission from an
     * inventory. {@see self::alreadyMatching()} keeps the count visible so the
     * page can say how many there were.
     *
     * @return list<ConfigBackupPlanItem>
     */
    public function chores(): array
    {
        return array_values(array_filter(
            $this->instructed(),
            static fn (ConfigBackupPlanItem $item): bool => $item->change->isMaterial(),
        ));
    }

    /**
     * How many of the things that would otherwise be chores are already true
     * here.
     *
     * Rendered as one muted line rather than as rows, because the reader's
     * question about them is "is anything hiding in there" and the answer is a
     * number.
     */
    public function alreadyMatching(): int
    {
        return count($this->instructed()) - count($this->chores());
    }

    /**
     * Whether this restore leaves the operator with anything at all to do.
     *
     * The page leads with the finish action when this is false, rather than
     * framing an empty list as work. Notes do not count — the encryption-key
     * note asks nothing of anyone who is not also restoring a database, which
     * is why it is a note.
     */
    public function hasChores(): bool
    {
        return [] !== $this->chores();
    }

    /**
     * The chores grouped the way the page shows them, empty sections dropped.
     *
     * @return array<string, list<ConfigBackupPlanItem>>
     */
    public function choresBySection(): array
    {
        $grouped = [];

        foreach (ConfigBackupSection::cases() as $section) {
            $items = array_values(array_filter(
                $this->chores(),
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
