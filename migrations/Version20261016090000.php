<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What's new (#165): everybody already here has last seen 0.3.1.
 *
 * The dialog opens after an UPDATE and never on a fresh install. What tells
 * the two apart is whether a person was here before: a user who exists when
 * this migration runs was, and is given 0.3.1 as the release they last saw,
 * so their first look shows the releases since — the starting point the
 * owner chose. A fresh install runs this with no users at all, and everyone
 * created afterwards starts at the release that is running
 * (WhatsNewFirstSighting), so nothing opens for them until the next update.
 *
 * Only where the key is absent, so a re-run, or a value something wrote
 * first, is left alone. `->` rather than jsonb's `?`, which Doctrine would
 * read as a parameter placeholder.
 *
 * down() takes the key off again. That is the whole of what up() did, and an
 * install rolled back past this release has no dialog to read it.
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record 0.3.1 as the last release existing users saw, for What\'s new';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user"
               SET settings = settings || '{"whats_new.seen_version": "0.3.1"}'::jsonb
             WHERE settings -> 'whats_new.seen_version' IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user" SET settings = settings - 'whats_new.seen_version'
            SQL);
    }
}
