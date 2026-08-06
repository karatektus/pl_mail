<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An Exchange master category gets a column of its own, apart from the folder.
 *
 * `label_binding.graph_folder_id` was carrying two different things. A folder
 * id is written by GraphFolderSyncer when a label is fed by a real Exchange
 * folder; a category id was written into the same column by the
 * push-a-new-category path, because both were "the Graph id of this label".
 *
 * They are not interchangeable, and the column is not merely an identifier:
 * GraphLabelPolicy decides whether a label is pushed as a folder MOVE or as a
 * many-to-many category by asking whether a graph_folder_id exists. So a label
 * that plMail created in Outlook as a category came back holding a folder id
 * and, from its next change onwards, was pushed as a location — a tag quietly
 * becoming a place, which Exchange cannot undo and the user never asked for.
 *
 * **Nothing is backfilled, and that is not laziness.** The two id shapes are
 * not reliably distinguishable after the fact — both are opaque strings — so a
 * migration that guessed would turn some real folder labels into categories,
 * which is the same class of damage in the other direction. A binding written
 * the old way simply keeps its folder id and goes on behaving as it did until
 * the next category sync fills the new column in: GraphCategorySyncer now
 * records the id for every master category it reads, so one sync repairs an
 * account without anybody being asked to do anything.
 *
 * Indexed, because the rename path looks a binding up by it.
 *
 * Reversible and lossless: dropping the column returns to the previous
 * behaviour, ids and all.
 */
final class Version20260806140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give an Exchange master category its own id column, apart from the folder id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE label_binding ADD graph_category_id VARCHAR(255) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_label_binding_graph_category_id
                ON label_binding (graph_category_id)
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN label_binding.graph_category_id IS
                'Exchange master category id. Deliberately not graph_folder_id, which GraphLabelPolicy reads to mean "this label is a folder".'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_label_binding_graph_category_id');
        $this->addSql('ALTER TABLE label_binding DROP graph_category_id');
    }
}
