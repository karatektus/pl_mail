<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * message_location.server_flags: each copy's flags as the server last
 * reported them (#415). Empty to start with; each folder's next listing
 * fills it, comparing against the message as it always did on the way.
 */
final class Version20261029190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message_location.server_flags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_location ADD server_flags JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_location DROP server_flags');
    }
}
