<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Monitoring\ReportKind;
use App\Domain\Monitoring\ReportedMail;
use App\Entity\Insight\InsightReport;
use App\Entity\Monitoring\CategoryReport;
use App\Service\Monitoring\ReportedMailProvider;
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
 * Admin → Reported mail: everything users have told plMail it got wrong, and
 * the four things an admin does with it.
 *
 * ── One list, two kinds ───────────────────────────────────────────────────────
 * A mail an extractor should have understood and did not, and a mail somebody
 * found in the wrong tab. They were built a year apart and lived as two panels
 * with two export buttons, which made sense to whoever wrote them and to nobody
 * using them: an admin working through the pile is asking "what is still mine
 * to do", and that question does not have two answers. {@see ReportedMail}
 * carries both, {@see ReportKind} names the difference, and everything in this
 * class is written against the pair.
 *
 * The route keeps its old name. Bookmarks and the admin nav point at
 * `/admin/insight-reports`, and a rename would be seventy-odd references and a
 * broken link in exchange for a tidier identifier nobody sees.
 *
 * ── Why the download is a POST ────────────────────────────────────────────────
 * For the reason ConfigBackupController states about its own export, and it
 * applies here at least as strongly: a GET that produced this file would be a
 * URL that could be embedded in an image tag on another site and fetched with
 * the admin's own cookies — and what comes back is other people's mail. Sender,
 * subject and body, of everyone who ever pressed a report button. So export is
 * POST, CSRF-checked, `no-store`, and there is no GET anywhere in this class
 * that does anything but render.
 *
 * ── Why the selection is a list of keys ───────────────────────────────────────
 * The export used to take a scope — everything, or only the unhandled part —
 * which answered the question an admin has on their first visit and none of the
 * questions they have afterwards. The one that actually comes up is "these six,
 * the ones about the shop", and no fixed scope can express it. So the page
 * posts the rows it has ticked, by `kind:id`, and an empty post still means
 * everything: a browser with JavaScript off submits exactly that, and a
 * download that silently returned nothing would be worse than a large file.
 *
 * ── Why one JSON document rather than JSONL ───────────────────────────────────
 * The reader of this file is a person — or a model — sitting down to write a
 * parser or change a rule, and they need to know what they are holding before
 * the first record: when it was taken, off which build, how many reports are in
 * it. JSONL has nowhere to put that; a header line that is itself a record
 * would mean every consumer needs a rule for telling the two apart. The size
 * argument that usually decides it for JSONL does not apply either — bodies are
 * capped at InsightReport::MAX_BODY_CHARS, so a thousand reports are a few
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
     * there a shape here worth acting on" actually reads.
     */
    private const int LIST_LIMIT = 100;

    /**
     * What the file calls itself, so a reader — and anything that ever ingests
     * this — can tell it from the config backup, which is also JSON and also
     * called plmail-something.
     */
    public const string DOCUMENT_FORMAT = 'plmail.reported-mail';

    /**
     * Bumped when the shape of a record changes, never when a value does.
     *
     * 2 is the unified document: every record now carries a `kind`, and the
     * file holds both kinds where version 1 held only missed-insight reports.
     * The old format name went with it — a consumer written against version 1
     * should fail on the name rather than half-read a file whose records it
     * only understands some of.
     */
    public const int DOCUMENT_VERSION = 2;

    public function __construct(
        private readonly ReportedMailProvider  $reports,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppVersion             $appVersion,
    ) {}

    #[Route('', name: 'panel', methods: ['GET'])]
    public function panel(): Response
    {
        return $this->renderPanel();
    }

    /**
     * Build the corpus and hand it over, without it ever existing anywhere but
     * in this response.
     */
    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->assertCsrf($request, 'insight_reports_export');

        /** @var list<string> $keys */
        $keys    = array_values(array_filter(
            (array) $request->request->all('keys'),
            static fn (mixed $key): bool => is_string($key) && '' !== $key,
        ));
        $reports = $this->reports->forExport($keys);

        $response = new Response(
            $this->document($reports, [] === $keys),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );

        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                'attachment',
                sprintf('plmail-reported-mail-%s.json', new DateTimeImmutable()->format('Y-m-d')),
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
    #[Route('/{kind}/{id}/handled', name: 'handled', requirements: ['kind' => 'insight|category', 'id' => '\d+'], methods: ['POST'])]
    public function handled(string $kind, int $id, Request $request): Response
    {
        $this->assertCsrf($request, 'insight_report_handled_' . $kind . '_' . $id);

        $report = $this->reports->find($kind, $id);

        if (null !== $report && null === $report->handledAt) {
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
    #[Route('/{kind}/{id}/delete', name: 'delete', requirements: ['kind' => 'insight|category', 'id' => '\d+'], methods: ['POST'])]
    public function delete(string $kind, int $id, Request $request): Response
    {
        $this->assertCsrf($request, 'insight_report_delete_' . $kind . '_' . $id);

        $report = $this->reports->find($kind, $id);

        if (null !== $report) {
            $this->entityManager->remove($report);
            $this->entityManager->flush();
        }

        return $this->renderPanel();
    }

    /**
     * Empty the done pile, of both kinds.
     *
     * Deliberate rather than bare: the button carries the reset panel's
     * `data-turbo-confirm`, so the browser asks before anything is posted. It
     * does NOT carry that panel's type-the-instance-name ceremony, and the
     * difference is the subject — a full reset destroys mail nobody has another
     * copy of, while this destroys reports somebody has already read, exported
     * and marked done. The ceremony is calibrated to what is lost, or it stops
     * being read.
     */
    #[Route('/clear-handled', name: 'clear_handled', methods: ['POST'])]
    public function clearHandled(Request $request): Response
    {
        $this->assertCsrf($request, 'insight_reports_clear_handled');

        $this->reports->clearHandled();

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
     * @param list<ReportedMail> $reports
     */
    private function document(array $reports, bool $everything): string
    {
        $document = [
            'format'     => self::DOCUMENT_FORMAT,
            'version'    => self::DOCUMENT_VERSION,
            'exportedAt' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            // Which build produced it. AppVersion answers "development" for a
            // checkout that was never built from a tag, and that is the honest
            // answer rather than an omission.
            'plmail'     => $this->appVersion->label(),
            // Said outright, because "43 reports" reads very differently
            // depending on whether it is the pile or somebody's pick from it.
            'scope'      => $everything ? 'all' : 'selection',
            'count'      => count($reports),
            'reports'    => array_map($this->record(...), $reports),
        ];

        // Pretty and unescaped: the reader is as likely to be a person reading
        // a German subject line as a parser, and ä helps neither of them.
        return (string) json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * One report, with everything its kind knows.
     *
     * The head of the record is the same for both — kind, when, who, which mail
     * — so a consumer can read the pile without branching, and the tail is
     * whichever evidence that kind actually has. Nothing is left out and
     * nothing is summarised: a record that dropped an empty field would make a
     * reader wonder whether the mail had no subject or whether the export
     * decided not to say, so a missing value is an explicit null.
     *
     * `line` is the same one-line rendering the panel shows and the copy button
     * copies. It is redundant with the fields beside it and it is here on
     * purpose: it is the form somebody pastes into a prompt or an issue, and
     * making them rebuild it from the record would be the export withholding
     * the thing the feature is for.
     *
     * @return array<string, mixed>
     */
    private function record(ReportedMail $report): array
    {
        $common = [
            'kind'        => $report->kind->value,
            'id'          => $report->id,
            'reportedAt'  => $report->reportedAt->format(DateTimeInterface::ATOM),
            // The reporter's address, so a shape that needs a question can have
            // one asked. Nothing else about the account: the report is about
            // the mail.
            'reportedBy'  => $report->reportedBy,
            'handledAt'   => $report->handledAt?->format(DateTimeInterface::ATOM),
            'fromName'    => $report->fromName,
            'fromAddress' => $report->fromAddress,
            'subject'     => $report->subject,
            'line'        => $report->asLine(),
        ];

        return [...$common, ...match (true) {
            $report->source instanceof InsightReport  => $this->insightFields($report->source),
            $report->source instanceof CategoryReport => $this->categoryFields($report->source),
        }];
    }

    /** @return array<string, mixed> */
    private function insightFields(InsightReport $report): array
    {
        return [
            'receivedAt' => $report->receivedAt?->format(DateTimeInterface::ATOM),
            // Last of the mail's own fields, and the longest — a record stays
            // readable in a terminal down to the line where the body starts.
            'note'       => $report->note,
            'bodyText'   => $report->bodyText,
        ];
    }

    /**
     * Every verdict and every input behind it.
     *
     * Structured as well as rendered into `line`, because these are the fields
     * somebody counts: "how often does the correspondent rule fire on a mail
     * with an unsubscribe header" is a question about a column, not about a
     * string.
     *
     * @return array<string, mixed>
     */
    private function categoryFields(CategoryReport $report): array
    {
        return [
            'messageId'        => $report->messageId,
            'filed'            => $report->filed->value,
            'filedMessage'     => $report->filedMessage?->value,
            'pinned'           => $report->pinned,
            'shouldBe'         => $report->shouldBe->value,
            'gmail'            => $report->gmail?->value,
            'rules'            => $report->rules?->value,
            'rulesSignal'      => $report->rulesSignal,
            'model'            => $report->model?->value,
            'aiAsked'          => $report->aiAsked,
            'source'           => $report->source,
            'overrideProvider' => $report->overrideProvider,
            'hasPlainText'     => $report->hasPlainText,
            'bulkHeaders'      => '' === $report->bulkHeaders ? null : $report->bulkHeaders,
            'listId'           => $report->listId,
        ];
    }

    /**
     * Every render of this frame goes through here, so the template's inputs
     * are always both present and always agree with each other — the list and
     * the counts are read after whichever mutation just ran, never before it.
     */
    private function renderPanel(): Response
    {
        $reports = $this->reports->latest(self::LIST_LIMIT);
        $counts  = $this->reports->counts();

        return $this->render('admin/insight_reports/_frame.html.twig', [
            'reports'      => $reports,
            'problems'     => $this->problems($reports),
            'pendingCount' => $counts['pending'],
            'handledCount' => $counts['handled'],
        ]);
    }

    /**
     * The chips: every problem this list actually contains, biggest first.
     *
     * Derived from the rows rather than enumerated, and that is the point. The
     * useful groups are not a fixed set — for a wrong tab they are a pair of
     * categories, and which pairs exist is a fact about this install's mail. A
     * chip for a problem nobody has reported is furniture; a missing chip for
     * one they have is the filter failing at the only job it has.
     *
     * @param list<ReportedMail> $reports
     *
     * @return list<array{problem: string, kind: string, count: int}>
     */
    private function problems(array $reports): array
    {
        $groups = [];

        foreach ($reports as $report) {
            $groups[$report->problem] ??= [
                'problem' => $report->problem,
                'kind'    => $report->kind->value,
                'count'   => 0,
            ];

            ++$groups[$report->problem]['count'];
        }

        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $groups;
    }
}
