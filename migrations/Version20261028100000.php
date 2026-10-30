<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether an IMAP folder keeps keywords of our choosing (#221 step 2): what
 * its PERMANENTFLAGS said, null until it has been asked. See
 * Mailbox::$keywordsAllowed.
 *
 * Nullable with no default, so existing rows read "not asked yet" and nothing
 * is written to them; dropping the column loses nothing but that answer.
 */
final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record whether an IMAP folder allows keywords: mailbox.keywords_allowed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD keywords_allowed BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP keywords_allowed');
    }
}
