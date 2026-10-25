<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let a calendar follow its owner's zone instead of keeping a copy of it (#343).
 *
 * Every calendar this install made was stamped with the owner's zone on the day
 * it was made, and nothing updated it when they changed theirs; the calendar
 * page was drawn in that copy, so after a change the grid and the header clock
 * disagreed. Null now means "follow plMail".
 *
 * Every calendar plMail owns goes back to following, including one whose zone
 * somebody did pick in the editor. That cannot be told apart from a copy — both
 * are just a zone name in the column — and a copy is by far the common case,
 * since the picker was the one place to change it and it sat under the name
 * and colour. Mirrored calendars keep theirs: it came from the remote, which
 * is a real answer about that calendar.
 */
final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendars follow the owner\'s timezone unless one is picked; local calendars go back to following.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar ALTER time_zone DROP DEFAULT');
        $this->addSql('ALTER TABLE calendar ALTER time_zone DROP NOT NULL');
        // Calendar::isSynced(), in SQL.
        $this->addSql("UPDATE calendar SET time_zone = NULL WHERE NOT (role = 'remote' AND remote_id IS NOT NULL)");
    }

    public function down(Schema $schema): void
    {
        // The owner's zone is not known here (it may itself be null, meaning the
        // install default), so a following calendar goes back to UTC, which is
        // what the column defaulted to before.
        $this->addSql("UPDATE calendar SET time_zone = 'UTC' WHERE time_zone IS NULL");
        $this->addSql('ALTER TABLE calendar ALTER time_zone SET NOT NULL');
        $this->addSql("ALTER TABLE calendar ALTER time_zone SET DEFAULT 'UTC'");
    }
}
