<?php

declare(strict_types=1);

namespace App\Controller\Insight;

use App\Controller\ChecksCsrf;
use App\Entity\Insight\InsightReport;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Insight\InsightReportRepository;
use App\Security\Voter\OwnershipVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The other direction of the extraction pipeline: a user saying "this mail
 * should have produced an insight, and it did not".
 *
 * InsightActionsController is this endpoint's mirror image — there a user waves
 * away something an extractor found, here a user names something no extractor
 * knows how to find yet. Both are one JSON POST from the reading pane, and this
 * one reads like that one deliberately: the same CSRF trait, the same
 * per-action token id, the same `\d+` on the id so a non-numeric one is a
 * routing 404 rather than a bigint cast, and the same idempotence, because the
 * second click of a slow double-click is not a second observation.
 *
 * ── Why the ownership check is the important line in this file ───────────────
 * Filing a report COPIES the mail — sender, subject and the first
 * InsightReport::MAX_BODY_CHARS of its plain text — into a table an
 * administrator can read and export. That makes an unchecked `{id}` an
 * exfiltration route rather than a privacy nicety: post somebody else's message
 * id and their mail lands, in full, in a panel the poster's admin downloads. So
 * the voter runs before anything is read off the message, and the dialog that
 * fronts this endpoint says in plain words where the content goes. The entity's
 * class doc argues the snapshot itself at length; this controller only takes it.
 *
 * ── Two methods, one path ────────────────────────────────────────────────────
 * GET renders the dialog into the shared modal frame (_partials/_modal.html.twig
 * points its turbo-frame at a URL), POST files the report. One path for both
 * because they are one resource seen twice — the form for a report, and the
 * report — and it keeps the Stimulus side from having to be told two URLs when
 * the template already handed it one.
 *
 * The browser half is assets/controllers/insight/report_controller.js.
 */
#[Route('/insights/report', name: 'app_insight_report_')]
#[IsGranted('IS_AUTHENTICATED')]
final class InsightReportController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly InsightReportRepository $reports,
        private readonly EntityManagerInterface  $entityManager,
    ) {
    }

    /**
     * The note dialog, for the modal frame. Reads nothing, writes nothing.
     *
     * It looks the report up so the dialog can open as a CORRECTION when this
     * person has reported this mail before — the note as it stands, in a field
     * they can edit — rather than as a blank form that implies nothing was
     * ever said.
     */
    #[Route('/{id}', name: 'dialog', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function dialog(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->render('insight/_report_dialog.html.twig', [
            'message' => $message,
            'report'  => $this->reports->findOneByMessageAndReporter($message, $this->reporter()),
        ]);
    }

    /**
     * File the report, or update the one this person already filed.
     *
     * Idempotent per (message, reporter) through the repository's guard rather
     * than through a unique constraint — InsightReportRepository
     * ::findOneByMessageAndReporter() explains why the rule cannot live in the
     * schema, and why two DIFFERENT people reporting the same newsletter are
     * two reports and not a duplicate.
     *
     * A second report is not refused, because refusing it would throw away the
     * one thing that got better on the second pass: the note. Somebody who
     * reports a mail, then reopens the dialog to say what they meant, has
     * corrected the report and not filed another one. `alreadyReported` travels
     * back so the dialog can say which of the two happened.
     */
    #[Route('/{id}', name: 'submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(Message $message, Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'insight-report');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        $reporter = $this->reporter();
        $existing = $this->reports->findOneByMessageAndReporter($message, $reporter);
        $note     = $this->note($request);

        if (null !== $existing) {
            // Only when there is one. A reopened dialog submitted empty is
            // somebody confirming, not somebody deleting what they wrote.
            if (null !== $note) {
                $existing->note = $note;
                $this->entityManager->flush();
            }

            return $this->json(['ok' => true, 'alreadyReported' => true]);
        }

        $report              = new InsightReport();
        $report->reportedBy  = $reporter;
        $report->account     = $message->account;
        $report->message     = $message;
        $report->fromAddress = $message->fromAddress;
        $report->fromName    = $message->fromName;
        $report->subject     = $message->subject;
        $report->receivedAt  = $message->receivedAt;
        // Plain text only, and only the first screenful of it: the export is a
        // corpus of mail SHAPES, and the shape is in the top of the mail rather
        // than in the eleventh quoted reply. The HTML part is never taken — a
        // parser is written against the text, and the markup would be most of
        // the budget.
        $report->bodyText    = null === $message->bodyText
            ? null
            : mb_substr($message->bodyText, 0, InsightReport::MAX_BODY_CHARS);
        $report->note        = $note;

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $this->json(['ok' => true, 'alreadyReported' => false]);
    }

    /**
     * The user's own words, or null when they gave none.
     *
     * json_decode() over Request::toArray(), which throws on an empty body —
     * and an empty body is the ordinary case here, since the note is optional
     * and the token rides in a header.
     */
    private function note(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);

        if (false === is_array($payload)) {
            return null;
        }

        $note = trim((string) ($payload['note'] ?? ''));

        if ('' === $note) {
            return null;
        }

        return mb_substr($note, 0, InsightReport::MAX_NOTE_CHARS);
    }

    private function reporter(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
