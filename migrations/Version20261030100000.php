<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rules that applied a label which is a folder become "Move to folder" rules
 * (#221 step 3).
 *
 * Before step 3, putting a label on mail on plain IMAP or Outlook MOVED it
 * when the label was a folder there — a rule "apply Receipts" filed the mail
 * into the Receipts folder. Step 3 separates the two: applying a label tags
 * and never moves, and moving is its own action. A rule written before then
 * meant the move, so it is turned into one.
 *
 * Only where the label is a folder on EVERY account the rule covers — its own
 * account, or each of the user's for a rule on all of them. On a Gmail
 * account the same rule only ever tagged, and as "Move to folder" it would
 * also take the mail out of the inbox; such a rule is left as it was, and
 * its owner can change it.
 */
final class Version20261030100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Turn rules that applied a folder label into Move to folder rules (#221 step 3).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE mail_rule r
               SET actions = (
                   SELECT jsonb_agg(
                       CASE
                           WHEN t.a->>'type' = 'applyLabel' AND jsonb_typeof(t.a->'labelId') = 'number' THEN
                               CASE
                                   WHEN EXISTS (
                                            SELECT 1 FROM account ac
                                             WHERE ac.usr_id = r.usr_id
                                               AND (r.account_id IS NULL OR ac.id = r.account_id)
                                        )
                                    AND NOT EXISTS (
                                            SELECT 1 FROM account ac
                                             WHERE ac.usr_id = r.usr_id
                                               AND (r.account_id IS NULL OR ac.id = r.account_id)
                                               AND NOT EXISTS (
                                                   SELECT 1 FROM label_binding b
                                                    WHERE b.account_id = ac.id
                                                      AND b.label_id = (t.a->>'labelId')::numeric::int
                                                      AND (b.mailbox_id IS NOT NULL OR b.graph_folder_id IS NOT NULL)
                                               )
                                        )
                                   THEN jsonb_set(t.a, '{type}', '"moveToFolder"')
                                   ELSE t.a
                               END
                           ELSE t.a
                       END
                       ORDER BY t.ord
                   )
                   FROM jsonb_array_elements(r.actions) WITH ORDINALITY AS t(a, ord)
               )
             WHERE jsonb_typeof(r.actions) = 'array'
               AND r.actions @> '[{"type": "applyLabel"}]'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE mail_rule r
               SET actions = (
                   SELECT jsonb_agg(
                       CASE WHEN t.a->>'type' = 'moveToFolder' THEN jsonb_set(t.a, '{type}', '"applyLabel"') ELSE t.a END
                       ORDER BY t.ord
                   )
                   FROM jsonb_array_elements(r.actions) WITH ORDINALITY AS t(a, ord)
               )
             WHERE jsonb_typeof(r.actions) = 'array'
               AND r.actions @> '[{"type": "moveToFolder"}]'
        SQL);
    }
}
