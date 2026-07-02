<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629130603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE message ALTER COLUMN in_reply_to DROP DEFAULT");
        $this->addSql("ALTER TABLE message ALTER COLUMN in_reply_to TYPE json USING in_reply_to::json");
        $this->addSql('ALTER TABLE message ALTER COLUMN "references" DROP DEFAULT');
        $this->addSql('ALTER TABLE message ALTER COLUMN "references" TYPE json USING "references"::json');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE message ALTER COLUMN in_reply_to TYPE text USING in_reply_to::text");
        $this->addSql("ALTER TABLE message ALTER COLUMN in_reply_to SET DEFAULT ''");
        $this->addSql('ALTER TABLE message ALTER COLUMN "references" TYPE text USING "references"::text');
        $this->addSql('ALTER TABLE message ALTER COLUMN "references" SET DEFAULT \'\'');
    }
}
