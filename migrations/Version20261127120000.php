<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * appearance_palette: the colours of a theme imported from VS Code, painted
 * over the built-in theme. See Appearance::$palette.
 *
 * Nullable and null on every existing row, which is "no imported theme", so
 * nothing renders differently until somebody imports one.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add appearance_palette, an imported theme\'s colours';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_palette JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_palette');
    }
}
