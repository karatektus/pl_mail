<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether action buttons show icons, words or both (#459).
 *
 * Defaults to 'icons', what the buttons already show, so a deploy changes
 * nothing anybody can see.
 */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the action button style to the user appearance, defaulted to the current icons';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" ADD appearance_action_buttons VARCHAR(16) DEFAULT 'icons' NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_action_buttons');
    }
}
