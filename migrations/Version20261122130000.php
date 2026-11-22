<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A finished entry per tick of a repeating task, tied to the task it came from
 * (#75). Nullable and empty on every task that exists, so no backfill. Cascading,
 * because an entry has nothing to say once its task is gone.
 */
final class Version20261122130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tie a finished entry of a repeating task to that task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD occurrence_of_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_task_occurrence_of ON task (occurrence_of_id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT fk_task_occurrence_of FOREIGN KEY (occurrence_of_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP COLUMN occurrence_of_id');
    }
}
