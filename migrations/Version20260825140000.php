<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A place to remember a saved signature, for stamping onto PDFs.
 *
 * The filename only. The image itself lives under var/uploads next to the
 * avatars, because a PNG has no business in a row read on every request.
 *
 * Additive and nullable, so it costs nothing on an existing install and the
 * down migration loses only the pointer — the files stay on disk, which is the
 * safe direction for a rollback.
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.signature, the filename of a saved handwritten signature.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD signature VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP signature');
    }
}
