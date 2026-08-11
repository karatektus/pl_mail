<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the per-account "labels stay local" toggle.
 *
 * There is no column to drop: the toggle lived at `labels.sync_to_provider` in
 * the `account.settings` jsonb bag, so this removes a key rather than altering
 * a table — the same shape as Version20260809120000, which retired the sync
 * cap. Nothing reads the key any more, so leaving it would be harmless; it goes
 * because a bag that accumulates dead keys stops being readable as the set of
 * things an account actually has.
 *
 * Mirroring is no longer a preference. Whether label structure reaches the
 * provider is now a question about the provider — Gmail has labels, Exchange
 * has folders, plain IMAP has folders that hold real mail and is still excluded
 * — which is Account::supportsLabelSync(), and it needs nothing stored.
 *
 * **Accounts that had the toggle off now start mirroring, and this migration
 * does not go back and push what they already have.** Labels created, renamed
 * or deleted from here on propagate; the tree as it stands does not, because
 * bulk-creating a user's whole local label structure on their provider is not
 * something a boot-time migration should start on every account at once. An
 * install that wants the existing tree mirrored re-syncs — `app:reset`, then a
 * fresh sync with mirroring unconditional.
 *
 * down() cannot restore the toggles: each account's answer is gone with the
 * key, and inventing a default would either re-impose local-only labels on
 * accounts that never chose it or claim consent that was never given.
 */
final class Version20260811093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the retired label-sync toggle from the account settings bag';
    }

    public function up(Schema $schema): void
    {
        // jsonb_exists(settings, …) rather than the `?` operator it spells: a
        // bare ? is a positional parameter placeholder long before the driver
        // considers asking Postgres what it means, and the statement dies on a
        // bound-parameter count instead of running.
        $this->addSql(<<<'SQL'
            UPDATE account
               SET settings = settings - 'labels.sync_to_provider'
             WHERE jsonb_exists(settings, 'labels.sync_to_provider')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigration(
            'Each account\'s label-sync choice is not recoverable once the key is deleted.'
        );
    }
}
