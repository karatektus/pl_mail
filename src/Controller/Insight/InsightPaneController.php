<?php

declare(strict_types=1);

namespace App\Controller\Insight;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Service\Insight\InsightPane;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The strip of insights above the mail list: the fragment that draws it, and
 * the button that waves it away.
 *
 * Whether it draws at all is not decided here. {@see InsightPane} owns that
 * sentence — off-switch, emptiness, dismissal — because the answer has three
 * readers and one of them is a test; this class only turns the answer into a
 * response. Its class doc also carries the argument for the strip existing at
 * all, against HappeningSoonController's written rejection of a panel in the
 * mailbox, and that is the doc to read before moving anything here.
 *
 * A route of its own rather than an include in the mailbox template, and that
 * is what makes the whole feature affordable: the strip arrives in a lazy
 * turbo-frame, so a mailbox render pays no query for it, and a user who has
 * switched it off pays nothing ever. The frame id is `insight_pane`; the
 * Stimulus side re-requests this URL when Mercure says `insights.changed`.
 *
 * Not folded onto InsightActionsController, though it is next door and shares
 * the prefix: that class is about the verbs on ONE insight — it resolves a
 * MailInsight, checks OwnershipVoter on it, stamps a column on it. Nothing
 * here has an insight to resolve. Both actions are about the STRIP, which
 * belongs to the user and not to any row, and the ownership question therefore
 * never arises: the user is the subject, so being signed in is the whole of
 * the authorisation.
 */
#[Route('/insights', name: 'app_insight_')]
#[IsGranted('IS_AUTHENTICATED')]
final class InsightPaneController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly InsightPane $pane,
    ) {
    }

    /**
     * The fragment. Cheap by construction: the switch is read off the settings
     * bag already in memory on the User, so a user who turned the strip off
     * never reaches the database at all, and everybody else pays one windowed,
     * three-row query. When it comes back empty the template renders nothing,
     * which is the common case and the one that has to stay free.
     *
     * `now` in UTC, the way HappeningSoonController resolves it and for the
     * same reason: every instant in this database is UTC, the window this is
     * compared against is an interval and not a wall clock, and a zone only
     * enters when a template turns an instant back into digits — which is
     * UserTimezoneResolver's job, in Twig, not this controller's.
     */
    #[Route('/pane', name: 'pane', methods: ['GET'])]
    public function pane(): Response
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->render('insight/_pane.html.twig', [
            'rows' => $this->pane->rowsFor($this->currentUser(), $now),
        ]);
    }

    /**
     * "Not now." Stamps the instant the strip was waved away; what comes back
     * afterwards is decided by comparing that instant against each insight's
     * createdAt, which is InsightPane::isDismissed()'s business.
     *
     * A second dismiss RE-STAMPS, and that is the opposite of what the
     * neighbouring InsightActionsController::dismiss does — deliberately. That
     * one keeps the first click's instant because it records a fact about one
     * card: when the user said no to THIS parcel, and a slow double-click must
     * not move it. This records a position on a moving list. The only reason
     * the strip is on screen to be dismissed twice is that something newer
     * arrived since the last dismissal, so the second click means "and that
     * one too" — keeping the older stamp would leave the newer insight
     * unclaimed and bring the strip straight back.
     *
     * No `if` around the write, then: there is no state in which re-stamping
     * is wrong, so guarding it would only be a branch that never pays.
     */
    #[Route('/pane/dismiss', name: 'pane_dismiss', methods: ['POST'])]
    public function dismiss(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertCsrf($request, 'insight-pane-dismiss');

        $dismissedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->currentUser()->setSetting(
            User::SETTING_INSIGHT_PANE_DISMISSED_AT,
            $dismissedAt->format(DateTimeImmutable::ATOM),
        );

        $em->flush();

        return $this->json(['ok' => true]);
    }

    /** Narrowed the way HappeningSoonController narrows it — IS_AUTHENTICATED is not a type. */
    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
