<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The mark's colourway becomes a user choice.
 *
 * One column, defaulted to 'berry' — the new product default — so every
 * existing account wakes up wearing the new mark rather than a NULL. Additive
 * and boring on purpose: the thirty-two legal values live in
 * App\Domain\Enum\Theme\LogoStyle, and an unknown value in this column is
 * simply ignored by the reader (applyArray tryFroms and keeps the old value),
 * so no CHECK constraint tries to duplicate the enum here.
 */
final class Version20260817170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add user.appearance_logo_style so the pl mark's colourway is a per-user setting";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_logo_style VARCHAR(16) DEFAULT \'berry\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_logo_style');
    }
}
