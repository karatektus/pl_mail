<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the newest-N sync cap.
 *
 * There is no column to drop: the cap lived at `sync.message_limit` in the
 * `account.settings` jsonb bag, so this removes a key rather than altering a
 * table. Nothing reads the key any more, so leaving it would be harmless — it
 * goes because a bag that accumulates dead keys stops being readable as the set
 * of things an account actually has.
 *
 * **This does not go and fetch the mail the cap kept out, deliberately.** An
 * account that was capped has mail on the provider that plMail never fetched,
 * and the sync cursors do not walk backwards on their own, so removing the
 * enforcement only means *future* runs are unbounded. Making the old mail
 * appear is a re-sync, which already has a command (`app:reset`) and is an
 * operator's decision rather than something a boot-time migration should start
 * on every capped account at once.
 *
 * down() cannot restore the caps: the number each account chose is gone with
 * the key, and inventing a default would re-impose a limit nobody asked for on
 * accounts that never had one.
 */
final class Version20260809120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the retired per-account sync cap from the account settings bag';
    }

    public function up(Schema $schema): void
    {
        // jsonb_exists(settings, …) rather than the `?` operator it spells:
        // a bare ? in a migration is a positional parameter placeholder long
        // before the driver considers asking Postgres what it means, and the
        // statement dies on a bound-parameter count instead of running.
        $this->addSql(<<<'SQL'
            UPDATE account
               SET settings = settings - 'sync.message_limit'
             WHERE jsonb_exists(settings, 'sync.message_limit')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigration(
            'The sync cap each account chose is not recoverable once the key is deleted.'
        );
    }
}
