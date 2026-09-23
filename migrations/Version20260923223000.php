<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The logo becomes two choices: an icon, then its paint.
 *
 * appearance_logo_motif names the icon — the pl mark or one of the nine motifs
 * beside it (App\Domain\Enum\Theme\LogoMotif). Defaulted to 'pl', so every
 * existing account keeps exactly the mark it has; nothing about an account's
 * colourway moves, since appearance_logo_style and appearance_logo_linked go on
 * meaning what they meant and the mark still reads them.
 *
 * appearance_logo_original says a motif wears its own design rather than a
 * colourway. FALSE for everyone: it is meaningless for the pl mark, which is
 * all anybody can have chosen so far, and choosing a motif in the settings
 * pane is what sets it.
 *
 * Additive, with no CHECK on the motif for the reason Version20260817170000
 * gives for the colourway: the legal values live in the enum, and the reader
 * ignores one it does not know.
 */
final class Version20260923223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.appearance_logo_motif and user.appearance_logo_original: the logo is an icon, then its paint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_logo_motif VARCHAR(16) DEFAULT \'pl\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance_logo_original BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_logo_motif');
        $this->addSql('ALTER TABLE "user" DROP appearance_logo_original');
    }
}
