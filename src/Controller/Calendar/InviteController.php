<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Calendar\MessageInvite;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\CalendarNotifier;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use App\Service\Calendar\InviteReader;
use App\Service\Calendar\InviteResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Answering an invitation from the message that carries it.
 *
 * Keyed on the message rather than the event, because that is what the person
 * is looking at and because one event can be described by several messages —
 * an id taken from the card is then also the proof that the card was rendered
 * for a message this user may read.
 *
 * Answers a Turbo Stream: the card re-renders in place with the new status,
 * and the toast carries the half the card cannot show — whether the organiser
 * was actually told. A redirect would take a reader out of the conversation
 * they are in the middle of.
 */
#[Route('/calendar/invite', name: 'app_calendar_invite_')]
#[IsGranted('IS_AUTHENTICATED')]
final class InviteController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly InviteReader           $invites,
        private readonly InviteResponder        $responder,
        private readonly EventExtractionRunner  $runner,
        private readonly EventReconciler        $reconciler,
        private readonly CalendarNotifier       $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/respond', name: 'respond', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function respond(Request $request, Message $message, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'calendar_invite' . $message->id);

        $status = ParticipationStatus::tryFrom($request->request->getString('status'));

        // needs-action is a real case of the enum and not a real answer: it is
        // the absence of one, and sending "I have not decided" to an organiser
        // is mail nobody wants.
        if (null === $status || false === $status->isAnswer()) {
            throw $this->createNotFoundException();
        }

        // Ownership included: the reader refuses a message belonging to
        // somebody else's account, so a null here covers both "not an
        // invitation" and "not yours".
        $invite = $this->invites->forMessage($message, $user);

        if (null === $invite) {
            throw $this->createNotFoundException();
        }

        if (false === $invite->canRespond && false === $invite->isOffer) {
            throw $this->createAccessDeniedException();
        }

        // An offer is added or it is not. "Maybe" is an answer to a person,
        // and nobody asked.
        if (true === $invite->isOffer && ParticipationStatus::Tentative === $status) {
            throw $this->createNotFoundException();
        }

        $sent = $this->responder->respond($invite, $status);

        $this->em->flush();

        if (true === $invite->isOffer) {
            // Nothing was sent and nothing could fail to be: the toast says
            // what happened to the calendar, which is all that happened.
            return $this->render('calendar/_invite_response.stream.html.twig', [
                'invite'       => $this->reread($message, $user),
                'toastMessage' => ParticipationStatus::Accepted === $status
                    ? 'calendar.invite.toast.added'
                    : 'calendar.invite.toast.not_added',
                'toastType'    => 'success',
            ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
        }

        return $this->render('calendar/_invite_response.stream.html.twig', [
            // Re-read rather than reused: the DTO was built before the answer
            // and would draw the card as it was a moment ago. Resetting drops
            // the reader's per-request memo so it rebuilds from the event it
            // has just changed.
            'invite'       => $this->reread($message, $user),
            'toastMessage' => true === $sent
                ? 'calendar.invite.toast.sent'
                : 'calendar.invite.toast.not_sent',
            'toastType'    => true === $sent ? 'success' : 'error',
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * "Apply the change" on a held card: the reader overrules the rule that
     * turned this message's claim down.
     *
     * The claim is not stored in a form that can be replayed — a link records
     * that it was made, not what it said — so the message is read again and
     * reconciled with `confirmed`. That is the same extraction that ran when it
     * arrived, over the same stored message, so what is applied is what was
     * held and not something the page posted.
     *
     * `confirmed` answers "may this sender change the event" and nothing else.
     * A held change that a newer one has since overtaken still loses to it,
     * and the toast says the change could not be applied rather than
     * pretending it was.
     */
    #[Route('/{id}/apply', name: 'apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(Request $request, Message $message, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'calendar_invite_apply' . $message->id);

        $invite = $this->invites->forMessage($message, $user);

        if (null === $invite) {
            throw $this->createNotFoundException();
        }

        if (false === $invite->isHeld) {
            throw $this->createAccessDeniedException();
        }

        $touched = $this->reconciler->reconcile($message, $this->runner->run($message), confirmed: true);
        $this->em->flush();

        if ([] !== $touched) {
            $this->notifier->publishCalendarChanged($user);
        }

        return $this->render('calendar/_invite_response.stream.html.twig', [
            'invite'       => $this->reread($message, $user),
            'toastMessage' => [] !== $touched ? 'calendar.invite.held.applied' : 'calendar.invite.held.not_applied',
            'toastType'    => [] !== $touched ? 'success' : 'error',
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    private function reread(Message $message, User $user): ?MessageInvite
    {
        $this->invites->reset();

        return $this->invites->forMessage($message, $user);
    }
}
