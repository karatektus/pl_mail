<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let an administrator make single sign-on the only way in (#169).
 *
 * One column on the single `oidc_config` row. False for every install that
 * exists today, so nothing changes until somebody ticks the box under Admin →
 * SSO.
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add oidc_config.password_login_disabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config ADD password_login_disabled BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config DROP password_login_disabled');
    }
}
