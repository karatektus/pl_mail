<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Work that has to happen once per installation after an update, and then
 * never again.
 *
 * ── Why this is not a migration ──────────────────────────────────────────────
 * A migration is the obvious home and it is the right one for schema. It is
 * the wrong one for repairs, which is what these actually are: a repair reads
 * the attachment store, re-runs the body sanitizer, asks the categoriser
 * something. A migration reaching into the service container is a migration
 * that breaks the day one of those services moves, and it breaks it in the
 * place where breakage costs most — halfway through an update, with the schema
 * half applied.
 *
 * ── Why this is not the entrypoint ───────────────────────────────────────────
 * The other obvious home, and it is downtime. docker-entrypoint.sh runs before
 * the server accepts a request, so a walk over every message put there is an
 * install that is unreachable until it finishes. Nobody updating at eight in
 * the evening signed up for that.
 *
 * ── Why this is not a backfill ───────────────────────────────────────────────
 * BackfillTaskInterface is deliberately manual — fourteen of them, every one
 * run by an administrator who decided to. That is right for "regenerate the
 * categories because the classifier changed", which is a choice. It is wrong
 * for "the body of this mail is in the wrong column", which is damage, and
 * which nobody should have to read a changelog to discover.
 *
 * A class may implement both, and the first one does: the same work, offered
 * to an administrator who wants it now and run automatically for everyone who
 * never reads the release notes.
 *
 * ── The contract ─────────────────────────────────────────────────────────────
 * IDEMPOTENT, and this is not advisory. The runner records an attempt BEFORE
 * running, so a task killed halfway — a restarted container, an OOM — is tried
 * again on the next pass with whatever it already did still done. A task that
 * cannot survive being run twice does not belong here.
 *
 * SELF-LIMITING. It runs on a schedule that knows nothing about how big the
 * job is, so it has to finish on its own rather than be interrupted.
 */
#[AutoconfigureTag('app.upgrade_task')]
interface UpgradeTaskInterface
{
    /** Stable identity, stored in the ledger. Renaming one runs it again. */
    public function getName(): string;

    /** One line, shown while it runs and in the task listing. */
    public function getDescription(): string;

    /** Returns a Command exit code (0 = success). */
    public function run(SymfonyStyle $io): int;
}
