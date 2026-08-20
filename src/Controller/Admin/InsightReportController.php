<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Entity\Insight\InsightReport;
use App\Repository\Insight\InsightReportRepository;
use App\Service\System\AppVersion;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Reported mail: the pile of mails users said an extractor should have
 * understood, and the four things an admin does with it.
 *
 * The reading end of the feedback edge InsightReport describes. That entity
 * argues why each row carries a SNAPSHOT of the mail rather than a pointer to
 * it; this controller exists because a snapshot nobody can get at is a snapshot
 * nobody writes a parser from.
 *
 * ── Why the download is a POST ────────────────────────────────────────────────
 * For the reason ConfigBackupController states about its own export, and it
 * applies here at least as strongly: a GET that produced this file would be a
 * URL that could be embedded in an image tag on another site and fetched with
 * the admin's own cookies — and what comes back is other people's mail. Sender,
 * subject and body, of everyone who ever pressed the report button. So export
 * is POST, CSRF-checked, `no-store`, and there is no GET anywhere in this class
 * that does anything but render.
 *
 * ── Why one JSON document rather than JSONL ───────────────────────────────────
 * The reader of this file is a person — or a model — sitting down to write a
 * new extractor from it, and they need to know what they are holding before
 * they read the first record: when it was taken, off which build, how many
 * reports are in it and whether it is the whole pile or only the unhandled
 * part. JSONL has nowhere to put that; a header line that is itself a record
 * would mean every consumer needs a rule for telling the two apart. The size
 * argument that usually decides it for JSONL does not apply either — the body
 * is capped at InsightReport::MAX_BODY_CHARS, so a thousand reports are a few
 * megabytes and nothing needs to stream. So: one self-describing object, pretty
 * printed, unescaped UTF-8, because it is read by eye at least as often as by
 * parser.
 *
 * ── Why exporting does not mark anything handled ──────────────────────────────
 * The obvious convenience, and it is wrong. Downloading is not processing: an
 * admin exports to look, exports again because the first file went to the wrong
 * machine, exports the whole pile to search it for one sender. If the download
 * stamped the rows, the second export would come back empty and the work would
 * silently be recorded as done by someone who had not started it. handledAt
 * says a human decided, which is the only thing it can mean and stay useful —
 * so it is written by the button that says so, and by nothing else.
 *
 * Every mutation re-renders the frame rather than redirecting, the way the
 * config backup panel's non-download actions do: these forms post into
 * `admin-insight-reports`, and the list they change is the frame itself.
 */
#[Route('/admin/insight-reports', name: 'app_admin_insight_reports_')]
#[IsGranted('ROLE_ADMIN')]
final class InsightReportController extends AbstractController
{
    use ChecksCsrf;

    /**
     * How much of the pile the panel shows.
     *
     * The panel is for triage and the export is for the corpus, so this is a
     * screenful budget rather than a limit on anything: what does not fit is
     * still in the file, and the newest hundred is what somebody deciding "is
     * there a shape here worth parsing" actually reads.
     */
    private const int LIST_LIMIT = 100;

    /** Everything exported, with only the unhandled part chosen by default. */
    private const string SCOPE_ALL = 'all';

    /**
     * What the file calls itself, so a reader — and anything that ever ingests
     * this — can tell it from the config backup, which is also JSON and also
     * called plmail-something.
     */
    public const string DOCUMENT_FORMAT = 'plmail.insight-reports';

    /**
     * Bumped when the shape of a record changes, never when a value does. There
     * is one consumer today and it is a human, but a version that starts at 1
     * costs nothing and a file that has to be dated to be understood costs a
     * morning.
     */
    public const int DOCUMENT_VERSION = 1;

    public function __construct(
        private readonly InsightReportRepository $reports,
        private readonly EntityManagerInterface  $entityManager,
        private readonly AppVersion              $appVersion,
    ) {}

    #[Route('', name: 'panel', methods: ['GET'])]
    public function panel(): Response
    {
        return $this->renderPanel();
    }

    /**
     * Build the corpus and hand it over, without it ever existing anywhere but
     * in this response.
     *
     * The scope comes from the form and defaults to the unhandled pile, because
     * that is the one with work left in it; "all" is there for the other
     * question — what has this install ever been told? — and for the admin who
     * wants to search their own history for a sender.
     */
    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->assertCsrf($request, 'insight_reports_export');

        $pendingOnly = self::SCOPE_ALL !== (string) $request->request->get('scope', '');
        $reports     = $this->reports->forExport($pendingOnly);

        $response = new Response(
            $this->document($reports, $pendingOnly),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );

        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                'attachment',
                sprintf('plmail-insight-reports-%s.json', (new DateTimeImmutable())->format('Y-m-d')),
            ),
        );

        // Other people's mail. Said to every intermediary in both dialects, for
        // the reason the config backup export says it.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * "I have dealt with this one."
     *
     * Idempotent, the way InsightActionsController::dismiss() is and for the
     * same reason: the second click of a slow double-click keeps the first
     * click's timestamp rather than moving it, so the stamp goes on saying when
     * the work was actually done.
     */
    #[Route('/{id}/handled', name: 'handled', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function handled(InsightReport $report, Request $request): Response
    {
        $this->assertCsrf($request, 'insight_report_handled_' . $report->id);

        if (null === $report->handledAt) {
            $report->handledAt = new DateTimeImmutable();
            $this->entityManager->flush();
        }

        return $this->renderPanel();
    }

    /**
     * The one row that was never worth keeping — a mis-click, or a mail whose
     * reporter would rather it were not sitting in a table.
     *
     * A real delete rather than a stamp, unlike everything else here: handledAt
     * is the record that a shape has been dealt with, and there is nothing to
     * record about a report that should not have been filed.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(InsightReport $report, Request $request): Response
    {
        $this->assertCsrf($request, 'insight_report_delete_' . $report->id);

        $this->entityManager->remove($report);
        $this->entityManager->flush();

        return $this->renderPanel();
    }

    /**
     * Empty the done pile.
     *
     * Deliberate rather than bare: the button carries the reset panel's
     * `data-turbo-confirm`, so the browser asks before anything is posted. It
     * does NOT carry that panel's type-the-instance-name ceremony, and the
     * difference is the subject — a full reset destroys mail nobody has another
     * copy of, while this destroys reports somebody has already read, exported
     * and marked done. The ceremony is calibrated to what is lost, or it stops
     * being read.
     *
     * Filtered in PHP rather than by a repository sweep: the repository has one
     * writer and two readers by design, and the pile is at most a few thousand
     * rows of which this touches the ones an admin has personally worked
     * through. A DELETE ... WHERE handled_at IS NOT NULL would be faster and
     * would also be a third reading of the table living somewhere other than
     * the repository.
     */
    #[Route('/clear-handled', name: 'clear_handled', methods: ['POST'])]
    public function clearHandled(Request $request): Response
    {
        $this->assertCsrf($request, 'insight_reports_clear_handled');

        foreach ($this->reports->forExport() as $report) {
            if (null !== $report->handledAt) {
                $this->entityManager->remove($report);
            }
        }

        $this->entityManager->flush();

        return $this->renderPanel();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The whole file, as an array, before it is encoded.
     *
     * A method of its own so the header and the records are written in one
     * place — the count is derived from the same list the records come from,
     * rather than being passed in beside it, which is how an export comes to
     * disagree with itself.
     *
     * @param list<InsightReport> $reports
     */
    private function document(array $reports, bool $pendingOnly): string
    {
        $document = [
            'format'      => self::DOCUMENT_FORMAT,
            'version'     => self::DOCUMENT_VERSION,
            'exportedAt'  => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            // Which build produced it. AppVersion answers "development" for a
            // checkout that was never built from a tag, and that is the honest
            // answer rather than an omission.
            'plmail'      => $this->appVersion->label(),
            'scope'       => $pendingOnly ? 'pending' : self::SCOPE_ALL,
            'count'       => count($reports),
            'reports'     => array_map($this->record(...), $reports),
        ];

        // Pretty and unescaped: the reader is as likely to be a person reading
        // a German subject line as a parser, and ä helps neither of them.
        return (string) json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * One report, with every snapshot column and the user's note.
     *
     * Nothing is left out and nothing is summarised. A record that dropped an
     * empty field would make a reader wonder whether the mail had no subject or
     * whether the export decided not to say — so a missing value is an explicit
     * null.
     *
     * @return array<string, mixed>
     */
    private function record(InsightReport $report): array
    {
        return [
            'id'          => $report->id,
            'reportedAt'  => $report->createdAt->format(DateTimeInterface::ATOM),
            // The reporter's address, so a shape that needs a question can have
            // one asked. Nothing else about the account: the report is about
            // the mail.
            'reportedBy'  => $report->reportedBy?->email,
            'handledAt'   => $report->handledAt?->format(DateTimeInterface::ATOM),
            'fromName'    => $report->fromName,
            'fromAddress' => $report->fromAddress,
            'subject'     => $report->subject,
            'receivedAt'  => $report->receivedAt?->format(DateTimeInterface::ATOM),
            // Last of the mail's own fields, and the longest — a record stays
            // readable in a terminal down to the line where the body starts.
            'note'        => $report->note,
            'bodyText'    => $report->bodyText,
        ];
    }

    /**
     * Every render of this frame goes through here, so the template's inputs
     * are always both present and always agree with each other — the list and
     * the count are read after whichever mutation just ran, never before it.
     */
    private function renderPanel(): Response
    {
        return $this->render('admin/insight_reports/_frame.html.twig', [
            'reports'      => $this->reports->latest(self::LIST_LIMIT),
            'pendingCount' => $this->reports->countPending(),
            'handledCount' => $this->reports->countHandled(),
        ]);
    }
}
