<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Domain\Enum\Monitoring\ReportKind;
use App\Domain\Monitoring\ReportedMail;
use App\Entity\Insight\InsightReport;
use App\Entity\Monitoring\CategoryReport;
use App\Repository\Insight\InsightReportRepository;
use App\Repository\Monitoring\CategoryReportRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Both piles of reports, as one pile.
 *
 * THE ONE PLACE THAT KNOWS THERE ARE TWO TABLES. Everything above it — the
 * panel, the triage buttons, the export — is written against {@see ReportedMail}
 * and never names a repository; everything below is two ordinary tables that
 * know nothing about each other. That seam is the whole design: the evidence a
 * wrong tab needs and the evidence a missed insight needs have no business being
 * the same rows, but the WORK is one worklist and has to be presented as one.
 *
 * MERGED IN PHP, NOT IN SQL. A UNION over two tables with almost no columns in
 * common would be a third schema written in a query, and it would have to be
 * rewritten every time either table gained a field. The pile is bounded by what
 * an admin reads in one sitting — the panel takes a screenful and the export a
 * few thousand rows of capped text — so sorting a merged array is measured in
 * microseconds and costs nothing worth a query nobody can read.
 */
final readonly class ReportedMailProvider
{
    public function __construct(
        private InsightReportRepository  $insights,
        private CategoryReportRepository $categories,
        private EntityManagerInterface   $entityManager,
    ) {
    }

    /**
     * The panel's list: newest first across both kinds.
     *
     * The limit is applied AFTER the merge as well as inside each repository,
     * so a hundred means a hundred rows on the page rather than a hundred of
     * each — otherwise a quiet week for one kind and a noisy one for the other
     * would silently double the list.
     *
     * @return list<ReportedMail>
     */
    public function latest(int $limit = 100): array
    {
        $rows = [
            ...array_map(ReportedMail::fromInsight(...), $this->insights->latest($limit)),
            ...array_map(ReportedMail::fromCategory(...), $this->categories->latest($limit)),
        ];

        usort($rows, static fn (ReportedMail $a, ReportedMail $b): int => $b->reportedAt <=> $a->reportedAt);

        return array_slice($rows, 0, $limit);
    }

    /**
     * What the export writes: oldest first, and only what was asked for.
     *
     * An empty selection means everything, which is the honest reading of a
     * form posted with nothing ticked — and the case that matters, because a
     * browser with JavaScript off submits exactly that. The alternative, an
     * empty file, would be a download that silently gave somebody nothing.
     *
     * @param list<string> $keys `kind:id`, as {@see ReportedMail::key()} writes them
     *
     * @return list<ReportedMail>
     */
    public function forExport(array $keys = []): array
    {
        $rows = [
            ...array_map(ReportedMail::fromInsight(...), $this->insights->forExport()),
            ...array_map(ReportedMail::fromCategory(...), $this->categories->forExport()),
        ];

        if ([] !== $keys) {
            $wanted = array_flip($keys);
            $rows   = array_values(array_filter(
                $rows,
                static fn (ReportedMail $row): bool => isset($wanted[$row->key()]),
            ));
        }

        usort($rows, static fn (ReportedMail $a, ReportedMail $b): int => $a->reportedAt <=> $b->reportedAt);

        return $rows;
    }

    /** @return array{pending: int, handled: int} */
    public function counts(): array
    {
        return [
            'pending' => $this->insights->countPending() + $this->categories->countPending(),
            'handled' => $this->insights->countHandled() + $this->categories->countHandled(),
        ];
    }

    /**
     * One report of either kind, by the key the page names it with.
     *
     * Null for anything that does not resolve, including a well-formed key for
     * a row somebody else has already deleted. The callers treat that as "no
     * longer there" rather than as an error: two admins working the same pile
     * is the ordinary case, not an attack.
     */
    public function find(string $kind, int $id): InsightReport|CategoryReport|null
    {
        return match (ReportKind::tryFrom($kind)) {
            ReportKind::Insight  => $this->insights->find($id),
            ReportKind::Category => $this->categories->find($id),
            null                 => null,
        };
    }

    /**
     * Delete everything anybody has marked done, of either kind.
     *
     * Read and removed through the entity manager rather than swept with a
     * DELETE, the way this has always been done here: the pile is at most a few
     * thousand rows of which this touches the ones an admin has personally
     * worked through, and a bulk statement would be a third reading of each
     * table living outside its repository.
     */
    public function clearHandled(): int
    {
        $cleared = 0;

        foreach ([...$this->insights->forExport(), ...$this->categories->forExport()] as $report) {
            if (null !== $report->handledAt) {
                $this->entityManager->remove($report);
                ++$cleared;
            }
        }

        $this->entityManager->flush();

        return $cleared;
    }
}
