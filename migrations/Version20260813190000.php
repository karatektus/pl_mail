<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give push silence something to be measured against.
 *
 * `gmail_last_push_at` recorded when Google last reached /gmail/push, and on
 * its own it can only be compared to the clock — which is what produced a
 * 36-hour threshold nobody could defend. Thirty-six hours of quiet is a dead
 * push on a busy mailbox and a perfectly ordinary Tuesday on a quiet one, and
 * no single number is right for both.
 *
 * `gmail_history_advanced_at` is the other half. Gmail pushes on any history
 * change at all, so the mailbox's own history advancing is exactly the event a
 * push was supposed to announce. A history that moved long after the last push
 * arrived is a change push missed — a fact about this account, not a guess
 * about mailbox habits — and a mailbox where it never moves says nothing and
 * raises nothing.
 *
 * Nullable, additive, no backfill and none possible: nothing recorded when
 * history last moved before this column existed. The correct value for every
 * existing row is the NULL it gets, which reads as "no evidence either way" and
 * leaves those accounts judged on their watch expiry alone until the next time
 * their history moves.
 */
final class Version20260813190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.gmail_history_advanced_at so push silence can be judged against real mailbox activity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD gmail_history_advanced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP gmail_history_advanced_at');
    }
}
