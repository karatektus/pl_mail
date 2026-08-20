<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How much the interface is allowed to move.
 *
 * Three steps rather than a checkbox, because the two complaints about
 * animation are different dials: things appearing from nowhere with no hint
 * that they arrived, and waiting for the interface to finish talking. See
 * App\Domain\Enum\Theme\MotionLevel, which holds the whole vocabulary.
 *
 * Defaults to 'full' for existing rows as well as new ones. Motion here is
 * additive — nothing lands anywhere different, nothing becomes usable later
 * than it did — so an upgrade is an interface that explains itself slightly
 * better, with a setting one click away for anybody who disagrees. Backfilling
 * to 'none' would ship the feature switched off, which nobody would ever find.
 *
 * An operating system asking for reduced motion overrules the column without
 * consulting it; that is enforced in assets/styles/motion.css rather than here,
 * because it is a property of the browser rendering the page and not of the
 * account.
 */
final class Version20260819090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the appearance motion tier (full, minimal, none)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE \"user\" ADD appearance_motion VARCHAR(16) DEFAULT 'full' NOT NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_motion');
    }
}
