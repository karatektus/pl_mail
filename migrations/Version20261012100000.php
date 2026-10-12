<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether the topbar's calendar button opens the full-width calendar in one
 * press. See App\Entity\Embeddable\Appearance::$calendarDirect.
 *
 * False for every existing user: the button keeps cycling until somebody
 * switches it.
 */
final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-user switch for opening the calendar in one press, off by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD appearance_calendar_direct BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_calendar_direct');
    }
}
