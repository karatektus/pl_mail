<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The wax seal becomes the icon an account starts on, and everyone who never
 * chose an icon gets it.
 *
 * Two column defaults move with LogoMotif::DEFAULT: the motif itself, and
 * appearance_logo_original, which is true now so that the seal arrives in its
 * own design rather than in a theme's colourway.
 *
 * The backfill is the part that needs a reason. "Never chose" is not stored
 * anywhere, so it is read off the row the way Version20260817200000 read it:
 * the pl mark, still in berry, still following the theme, is exactly what an
 * account that never opened the logo picker holds. Anyone who picked another
 * icon, another colourway, or unlinked the mark from the theme made a choice,
 * and keeps it.
 *
 * It cannot tell an untouched account from one that looked at ten icons and
 * deliberately stayed on the default mark. Those accounts get the seal too, and
 * the mark is one click away under Settings → Appearance → Logo.
 *
 * down() puts the column defaults back and leaves the rows alone. By then a
 * wax seal on a row is indistinguishable from one somebody picked, and turning
 * those back into the mark would undo real choices to restore a default.
 */
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the wax seal the default icon; accounts that never chose one move to it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER appearance_logo_motif SET DEFAULT \'wax-seal\'');
        $this->addSql('ALTER TABLE "user" ALTER appearance_logo_original SET DEFAULT TRUE');
        $this->addSql(<<<'SQL'
            UPDATE "user"
               SET appearance_logo_motif = 'wax-seal',
                   appearance_logo_original = TRUE
             WHERE appearance_logo_motif = 'pl'
               AND appearance_logo_style = 'berry'
               AND appearance_logo_linked = TRUE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER appearance_logo_motif SET DEFAULT \'pl\'');
        $this->addSql('ALTER TABLE "user" ALTER appearance_logo_original SET DEFAULT FALSE');
    }
}
