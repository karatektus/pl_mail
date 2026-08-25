<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember when a provider permanently refuses a change we try to push.
 *
 * A refused export leaves the change applied locally and nowhere else — mail
 * marked read on screen, never marked read at Gmail, and quietly reverted by
 * the next sync. Until now the only trace was a log line, so the divergence was
 * invisible to the person it happened to.
 *
 * Additive and nullable; cleared by the next export that succeeds.
 */
final class Version20260825190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.export_refused_reason, why the provider last refused a change permanently.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD export_refused_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP export_refused_reason');
    }
}
