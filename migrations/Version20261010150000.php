<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261010150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Shared Gmail user/project quota budget and cooldown'; }
    public function up(Schema $schema): void { $this->addSql('CREATE TABLE gmail_quota_state (quota_key VARCHAR(64) NOT NULL, credits DOUBLE PRECISION NOT NULL, updated_at DOUBLE PRECISION NOT NULL, blocked_until DOUBLE PRECISION NOT NULL, warning VARCHAR(32) DEFAULT NULL, pending_ids JSONB DEFAULT \'[]\' NOT NULL, PRIMARY KEY(quota_key))'); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Do not erase live Gmail quota/cooldown state during rollback; wait for workers to stop and budgets to expire.'); }
}
