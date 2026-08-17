<?php

declare(strict_types=1);

namespace App\Controller\Insight;

use App\Controller\ChecksCsrf;
use App\Entity\Insight\MailInsight;
use App\Security\Voter\OwnershipVoter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The user's verbs on an insight. There is exactly one: waving it away.
 *
 * Dismissal is a write to a column rather than a delete, and that is the
 * entity's decision, not this controller's — MailInsight::$dismissedAt records
 * at length why the row must survive its own dismissal (the dedupe key keeps
 * absorbing the carrier's follow-up mails silently; deleting the row would
 * resurrect the card on the next status update). This endpoint only stamps the
 * time.
 *
 * A JSON POST rather than a form: the button lives on a card inside the
 * Happening Soon panel, which is a turbo-frame fragment — a form submission
 * would navigate the frame, and the whole point of dismissing is that the card
 * leaves and everything else stays. The Stimulus side is
 * assets/controllers/insight/radar_controller.js.
 */
#[Route('/insights', name: 'app_insight_')]
#[IsGranted('IS_AUTHENTICATED')]
final class InsightActionsController extends AbstractController
{
    use ChecksCsrf;

    /**
     * `{id}` constrained to digits for the reason MailController::thread()
     * states: a non-numeric id must 404 at the router rather than reach the
     * entity resolver as a bigint that is not one.
     */
    #[Route('/{id}/dismiss', name: 'dismiss', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dismiss(MailInsight $insight, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertCsrf($request, 'insight-dismiss');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $insight);

        // Idempotent: the second click of a slow double-click keeps the first
        // click's timestamp rather than moving it.
        if (null === $insight->dismissedAt) {
            $insight->dismissedAt = new DateTimeImmutable();
            $em->flush();
        }

        return $this->json(['ok' => true]);
    }
}
