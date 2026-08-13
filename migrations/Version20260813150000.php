<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The appearance section grows four groups: mail list display, typography, and
 * per-surface density.
 *
 * Every column is additive and every default is what the app already did, so a
 * deploy changes nothing anybody can see. That is the whole point of the
 * defaults being here rather than only in PHP: an existing row gets the value
 * at ALTER time, so an install that never opens the settings pane keeps its
 * current look for good.
 *
 *  - account_corner / list_avatars default TRUE — the list draws both today.
 *  - preview_lines defaults 1 — one truncated line, which is what the row has.
 *  - unread_emphasis defaults 'standard' — the tint the row already carries.
 *  - font_family 'system' and font_scale 1.0 — the stack and size in app.css.
 *  - the three surface densities are NULLABLE and default NULL, which means
 *    "follow the global density". Null is the value, not a missing one: it is
 *    also how a surface goes back to following after having been overridden,
 *    so there is nothing to backfill and no state a fresh row starts in that
 *    an old row cannot reach.
 *
 * appearance_ is the embeddable's columnPrefix — these are columns on the
 * user table, not a table of their own. Quoted, because `user` is reserved in
 * Postgres and the entity spells it that way too.
 */
final class Version20260813150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the mail-list display, typography and per-surface density appearance settings to the user table, all defaulted to the current behaviour';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                ADD appearance_account_corner BOOLEAN DEFAULT true NOT NULL,
                ADD appearance_list_avatars BOOLEAN DEFAULT true NOT NULL,
                ADD appearance_preview_lines SMALLINT DEFAULT 1 NOT NULL,
                ADD appearance_unread_emphasis VARCHAR(16) DEFAULT 'standard' NOT NULL,
                ADD appearance_font_family VARCHAR(16) DEFAULT 'system' NOT NULL,
                ADD appearance_font_scale DOUBLE PRECISION DEFAULT 1 NOT NULL,
                ADD appearance_sidebar_density VARCHAR(16) DEFAULT NULL,
                ADD appearance_list_density VARCHAR(16) DEFAULT NULL,
                ADD appearance_reading_density VARCHAR(16) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                DROP appearance_account_corner,
                DROP appearance_list_avatars,
                DROP appearance_preview_lines,
                DROP appearance_unread_emphasis,
                DROP appearance_font_family,
                DROP appearance_font_scale,
                DROP appearance_sidebar_density,
                DROP appearance_list_density,
                DROP appearance_reading_density
            SQL);
    }
}
