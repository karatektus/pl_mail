<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Domain\DTO\Calendar\MessageInvite;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\InviteReader;
use App\Service\Calendar\InviteResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
    public function __construct(
        private readonly InviteReader           $invites,
        private readonly InviteResponder        $responder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/respond', name: 'respond', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function respond(Request $request, Message $message): Response
    {
        if (false === $this->isCsrfTokenValid('calendar_invite' . $message->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user   = $this->currentUser();
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

        if (false === $invite->canRespond) {
            throw $this->createAccessDeniedException();
        }

        $sent = $this->responder->respond($invite, $status);

        $this->em->flush();

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

    private function reread(Message $message, User $user): ?MessageInvite
    {
        $this->invites->reset();

        return $this->invites->forMessage($message, $user);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
