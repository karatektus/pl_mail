<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Passwords stretched in the browser. See App\Security\PasswordKdf.
 *
 * The salt and iteration count are null for every existing row, which is
 * exactly right: a null salt means "the stored hash is of the password
 * itself", and that is what every existing row holds. Nobody is locked out —
 * each account moves over on its next sign-in through the form.
 *
 * password_change_required is false for everybody. The accounts an
 * administrator created before this existed were created under the old
 * promise, and springing a forced change on them now would read as a fault.
 *
 * Reversible in the schema, but not in effect: going down drops the salts, and
 * every account that had moved over is then left with a hash of an auth hash
 * nobody can produce. `app:user:password` is the way back for those.
 */
final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the salt and iteration count of browser-stretched passwords, and the forced-change flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD kdf_salt VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD kdf_iterations INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD password_change_required BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP kdf_salt');
        $this->addSql('ALTER TABLE "user" DROP kdf_iterations');
        $this->addSql('ALTER TABLE "user" DROP password_change_required');
    }
}
