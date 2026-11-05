<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\ReadableBody;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills Message::$bodyHtmlText for mail stored before the column existed (#477).
 *
 * New mail fills it as it is stored (Message::deriveBodyHtmlText()). Before
 * that, a message sent as HTML alone was indexed by its subject and sender and
 * nothing else, and the same went for its embedding and its thread's summary.
 * Writing the column fixes the first on its own: search_vector is generated,
 * and Postgres builds it again for every row written here.
 *
 * THE EMBEDDING IS DROPPED, because it was made from the headers alone and
 * nothing else would ever replace it — the embedding passes only look for
 * mail that has none. Dropped, it is made again by the nightly sweep or the
 * next search, from the words this writes (see EmbeddingCatchUp). A summary
 * needs nothing: MailController::storedSummary() rebuilds the transcript on
 * every open, sees that it changed and treats the stored summary as out of
 * date.
 *
 * DBAL and not the ORM. Hydrating a message means its headers, its three
 * bodies and its flags, for a column computed from two of them; and the
 * entity would compute it again on flush anyway. The rows read are the ones
 * whose text part does not hold the words — the same test ReadableBody makes —
 * and keyset pagination by id, so a row that turns out to have no words in its
 * HTML either (an image-only newsletter) is passed once and not read forever.
 *
 * Safe to run again: a row it filled is not selected the second time.
 */
final readonly class HtmlBodyTextBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 200;

    /**
     * The rows ReadableBody::htmlWords() could answer for: an HTML body, and
     * either a Graph id or a text part with nothing but whitespace in it.
     * `!~ '[^[:space:]]'` is "has no visible character", the SQL for PHP's
     * `'' === trim(...)`, close enough that the PHP side decides the rest.
     */
    private const string CANDIDATES = <<<'SQL'
        m.body_html_text IS NULL
        AND coalesce(m.body_html_safe, m.body_html, '') <> ''
        AND (m.graph_id IS NOT NULL OR m.body_text IS NULL OR m.body_text !~ '[^[:space:]]')
        SQL;

    public function __construct(
        private Connection $connection,
    ) {}

    public function getName(): string
    {
        return 'html-body-text';
    }

    public function getDescription(): string
    {
        return 'Fill Message.bodyHtmlText for mail sent as HTML only, so search, embeddings and summaries read its body.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = (int) $this->connection->fetchOne('SELECT count(*) FROM message m WHERE ' . self::CANDIDATES);

        if (0 === $total) {
            $io->success('Nothing to fill — every message with an HTML body has its words stored already, or a text part.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId = 0;
        $filled = 0;

        while (true) {
            /** @var list<array{id: int|string, body_text: ?string, body_html: ?string, body_html_safe: ?string, graph_id: ?string}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT m.id, m.body_text, m.body_html, m.body_html_safe, m.graph_id FROM message m'
                . ' WHERE m.id > :last AND ' . self::CANDIDATES
                . ' ORDER BY m.id LIMIT :limit',
                ['last' => $lastId, 'limit' => self::BATCH_SIZE],
                ['last' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
            );

            if ([] === $rows) {
                break;
            }

            $written = [];

            $this->connection->beginTransaction();

            try {
                foreach ($rows as $row) {
                    $id     = (int) $row['id'];
                    $lastId = $id;
                    $words  = ReadableBody::htmlWords($row['body_text'], $row['body_html'], $row['body_html_safe'], null !== $row['graph_id']);

                    $io->progressAdvance();

                    if (null === $words) {
                        continue;
                    }

                    $this->connection->executeStatement(
                        'UPDATE message SET body_html_text = :words WHERE id = :id AND body_html_text IS NULL',
                        ['words' => $words, 'id' => $id],
                        ['id' => ParameterType::INTEGER],
                    );

                    $written[] = $id;
                }

                if ([] !== $written) {
                    $this->connection->executeStatement(
                        'DELETE FROM message_embedding WHERE message_id IN (:ids)',
                        ['ids' => $written],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }

                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();

                throw $e;
            }

            $filled += count($written);
        }

        $io->progressFinish();
        $io->success(sprintf(
            'Stored the words of %d HTML-only message(s). Their embeddings were dropped and are made again by the next index pass.',
            $filled,
        ));

        return Command::SUCCESS;
    }
}
