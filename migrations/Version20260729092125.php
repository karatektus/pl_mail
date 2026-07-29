<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729092125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.settings — a free-form jsonb bag for per-user preferences that do not warrant a column, mirroring account.settings. First use: which admin panels the user has collapsed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD settings JSONB DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP settings');
    }
}
