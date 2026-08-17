<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The mark learns to follow the theme — unless its owner already disagreed.
 *
 * appearance_logo_linked says whether the "pl" mark wears the theme's own
 * colourway (every logo style is a theme now, value for value) or the
 * separately chosen appearance_logo_style. New accounts and everyone who never
 * touched the logo picker get TRUE: for them the linked behaviour is strictly
 * an upgrade, since their mark was the product default and the default theme's
 * mark is the same default.
 *
 * Anyone whose appearance_logo_style is NOT 'berry' made a choice. They opened
 * the appearance page, looked at thirty-two colourways and picked one — and
 * linking them would silently overwrite that choice with whatever their theme
 * happens to map to (for the classic seven themes, straight back to the berry
 * they navigated away from). So the backfill sets them to FALSE and their mark
 * stays exactly as they left it; the new toggle is there if they want it.
 */
final class Version20260817200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.appearance_logo_linked; existing non-default colourway choices stay unlinked';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_logo_linked BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('UPDATE "user" SET appearance_logo_linked = FALSE WHERE appearance_logo_style != \'berry\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_logo_linked');
    }
}
