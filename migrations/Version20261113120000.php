<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The message list's settings leave Appearance for a group of their own,
 * `message_list_*`, and gain eight new ones (#608).
 *
 * The five that existed are COPIED and then dropped, not renamed, because one
 * of them changes shape. `appearance_preview_lines` was a count that also
 * decided, as a side effect, where a row went to one line: 1 made it 48rem,
 * 0 and 2 left it at 75rem. That is now two settings, and both are filled so
 * the list looks the same the morning after:
 *
 *   preview_lines  →  preview   layout
 *   0                 none      wide
 *   1                 one       fit
 *   2                 two       wide
 *
 * The new columns carry defaults equal to what the list drew before the option
 * existed, so the ADD fills every existing row and they are NOT NULL from the
 * start.
 *
 * down() puts the five back from the new columns. Inline preview, which the
 * old count cannot say, goes back as 1, and the eight new options are lost.
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the message list settings off Appearance and add the new row options';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                ADD message_list_layout VARCHAR(16) DEFAULT 'fit' NOT NULL,
                ADD message_list_preview VARCHAR(16) DEFAULT 'one' NOT NULL,
                ADD message_list_attachments VARCHAR(16) DEFAULT 'icon' NOT NULL,
                ADD message_list_labels VARCHAR(16) DEFAULT 'line' NOT NULL,
                ADD message_list_sender VARCHAR(16) DEFAULT 'everyone' NOT NULL,
                ADD message_list_date VARCHAR(16) DEFAULT 'smart' NOT NULL,
                ADD message_list_message_count BOOLEAN DEFAULT true NOT NULL,
                ADD message_list_replied BOOLEAN DEFAULT false NOT NULL,
                ADD message_list_star VARCHAR(16) DEFAULT 'hover' NOT NULL,
                ADD message_list_avatars BOOLEAN DEFAULT true NOT NULL,
                ADD message_list_account_corner BOOLEAN DEFAULT true NOT NULL,
                ADD message_list_unread_emphasis VARCHAR(16) DEFAULT 'standard' NOT NULL,
                ADD message_list_tab_unread_count BOOLEAN DEFAULT true NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE "user" SET
                message_list_preview = CASE appearance_preview_lines WHEN 0 THEN 'none' WHEN 1 THEN 'one' ELSE 'two' END,
                message_list_layout = CASE appearance_preview_lines WHEN 1 THEN 'fit' ELSE 'wide' END,
                message_list_avatars = appearance_list_avatars,
                message_list_account_corner = appearance_account_corner,
                message_list_unread_emphasis = appearance_unread_emphasis,
                message_list_tab_unread_count = appearance_tab_unread_count
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                DROP appearance_preview_lines,
                DROP appearance_list_avatars,
                DROP appearance_account_corner,
                DROP appearance_unread_emphasis,
                DROP appearance_tab_unread_count
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                ADD appearance_preview_lines SMALLINT DEFAULT 1 NOT NULL,
                ADD appearance_list_avatars BOOLEAN DEFAULT true NOT NULL,
                ADD appearance_account_corner BOOLEAN DEFAULT true NOT NULL,
                ADD appearance_unread_emphasis VARCHAR(16) DEFAULT 'standard' NOT NULL,
                ADD appearance_tab_unread_count BOOLEAN DEFAULT true NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE "user" SET
                appearance_preview_lines = CASE message_list_preview WHEN 'none' THEN 0 WHEN 'two' THEN 2 ELSE 1 END,
                appearance_list_avatars = message_list_avatars,
                appearance_account_corner = message_list_account_corner,
                appearance_unread_emphasis = message_list_unread_emphasis,
                appearance_tab_unread_count = message_list_tab_unread_count
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                DROP message_list_layout,
                DROP message_list_preview,
                DROP message_list_attachments,
                DROP message_list_labels,
                DROP message_list_sender,
                DROP message_list_date,
                DROP message_list_message_count,
                DROP message_list_replied,
                DROP message_list_star,
                DROP message_list_avatars,
                DROP message_list_account_corner,
                DROP message_list_unread_emphasis,
                DROP message_list_tab_unread_count
            SQL);
    }
}
