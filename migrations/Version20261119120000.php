<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * message.placed_by_headers (#676): a row threaded by its headers rather
 * than by its provider thread key. See App\Entity\Mail\Message::$placedByHeaders.
 *
 * Set on the rows that are already so: a provider key that differs from the
 * key of the conversation the row is in. A sync's merge of a sent copy leaves
 * rows like that (the JMAP client sync has done so for a while); whatever else
 * did, the flag keeps the row where it is now when threads are rebuilt, which
 * is the conversation somebody has been reading it in.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add message.placed_by_headers and set it where a row is kept out of its provider thread';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD placed_by_headers BOOLEAN DEFAULT false NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE message m
               SET placed_by_headers = true
              FROM message_thread t
             WHERE m.thread_id = t.id
               AND m.provider_thread_key IS NOT NULL
               AND m.provider_thread_key IS DISTINCT FROM t.provider_thread_key
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP placed_by_headers');
    }
}
