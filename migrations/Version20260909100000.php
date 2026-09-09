<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How old a sign-in is, and how long the last one lasted.
 *
 * Both exist to recognise one thing: Google expires refresh tokens issued by an
 * OAuth app still in "Testing" publishing status after about a week, which is
 * the state nearly every self-hosted install is in. The provider reports it as
 * `invalid_grant` — the same code it uses for a revoked consent and a changed
 * password — so the only thing that tells them apart is how long the grant
 * lasted, and nothing was recording that.
 *
 * Null on every existing account and deliberately left null: an account
 * connected before this cannot have its history invented, and nothing infers
 * anything from a null. They fill in on the next reconnect.
 */
final class Version20260909100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when an OAuth grant was issued, and how long the previous one lasted.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE account
                ADD oauth_granted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                ADD oauth_prior_grant_hours INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP oauth_granted_at, DROP oauth_prior_grant_hours');
    }
}
