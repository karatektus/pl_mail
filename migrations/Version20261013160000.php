<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where "Keep me signed in" tokens are kept now that they are kept on the
 * server — Symfony's DoctrineTokenProvider, configured in security.yaml. The
 * table is that provider's own, column for column.
 *
 * Every remember-me cookie issued before this is a signed cookie the new
 * handler does not read: whoever relied on one signs in once more.
 *
 * Reversible.
 */
final class Version20261013160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep remember-me tokens on the server, so signing out ends them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, class VARCHAR(100) DEFAULT '' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY (series))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rememberme_token');
    }
}
