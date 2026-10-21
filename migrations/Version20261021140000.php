<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give app passwords scopes (#NNN).
 *
 * One jsonb column, and it comes with a BACKFILL THAT MATTERS: every app
 * password that exists when this runs is granted all three scopes.
 *
 * The column's default is `[]`, which is the right default for a credential
 * nobody has decided anything about — it can do nothing. But a token created
 * before scopes existed was not a token somebody deliberately limited: it was
 * a token that had everything, because everything was all there was. Leaving
 * those at `[]` would revoke every phone, tablet and JMAP client on the
 * installation at the moment of upgrade, and the symptom — apps that stop
 * syncing and a settings page that shows them as "no access" — reads like a
 * broken upgrade rather than like a policy change.
 *
 * So the default is `[]` and the migration writes `["read","send","delete"]`
 * over every existing row. A row created after this runs gets whatever its
 * creator granted, which is the settings page's three ticked boxes by default.
 *
 * Reversible in schema only: down() drops the column and with it the record of
 * what each credential was allowed to do. That is the correct meaning of going
 * back to a version that had no such concept.
 */
final class Version20261021140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_token.scopes, granting every existing app password all three scopes (read, send, delete) so no connected client is cut off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE api_token ADD scopes JSONB DEFAULT '[]' NOT NULL");

        // The backfill, and the reason this migration is not a one-liner.
        $this->addSql(
            "UPDATE api_token SET scopes = '[\"read\", \"send\", \"delete\"]'::jsonb",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP scopes');
    }
}
