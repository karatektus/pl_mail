<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Entity\Calendar\EventProposal;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\Proposal\ProposalReader;
use App\Service\Calendar\Proposal\ProposalResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Answering a proposed date from the message that suggested it.
 *
 * Keyed on the message rather than the proposal, exactly as InviteController is
 * and for the same reason: the message is what the person is looking at, and an
 * id taken from the card is then also the proof that the card was rendered for a
 * message this user may read. ProposalReader refuses a message belonging to
 * somebody else's account, so a null covers both "nothing proposed" and "not
 * yours" without a second ownership check that could be forgotten.
 *
 * Answers a Turbo Stream. Both answers end the same way — the card goes — and a
 * redirect would take a reader out of the conversation they are in the middle
 * of. The toast carries what the card cannot: that an event now exists, or that
 * the refusal will hold next time.
 */
#[Route('/calendar/proposal', name: 'app_calendar_proposal_')]
#[IsGranted('IS_AUTHENTICATED')]
final class ProposalController extends AbstractController
{
    public function __construct(
        private readonly ProposalReader         $proposals,
        private readonly ProposalResponder      $responder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/accept', name: 'accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(Request $request, Message $message): Response
    {
        $proposal = $this->resolve($request, $message);
        $event    = $this->responder->accept($proposal);

        $this->em->flush();

        // A missing calendar is the one failure this can have that is not the
        // user's mistake, and it is worth saying out loud rather than silently
        // dropping the card: the proposal is gone either way, and a person who
        // pressed Add and got nothing would have no idea why.
        return $this->answered(
            $message,
            null !== $event ? 'calendar.proposal.toast.added' : 'calendar.proposal.toast.no_calendar',
            null !== $event ? 'success' : 'error',
        );
    }

    #[Route('/{id}/dismiss', name: 'dismiss', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dismiss(Request $request, Message $message): Response
    {
        $this->responder->dismiss($this->resolve($request, $message));

        $this->em->flush();

        return $this->answered($message, 'calendar.proposal.toast.dismissed', 'success');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The proposal this card was drawn from, or a refusal.
     *
     * One resolver for both actions, so neither can forget the token or the
     * ownership check — the pattern ThreadStatusController established.
     */
    private function resolve(Request $request, Message $message): EventProposal
    {
        if (false === $this->isCsrfTokenValid(
            'calendar_proposal' . $message->id,
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $proposal = $this->proposals->forMessage($message, $user);

        if (null === $proposal) {
            throw $this->createNotFoundException();
        }

        return $proposal;
    }

    private function answered(Message $message, string $toastMessage, string $toastType): Response
    {
        return $this->render('calendar/_proposal_response.stream.html.twig', [
            'messageId'    => $message->id,
            'toastMessage' => $toastMessage,
            'toastType'    => $toastType,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
}
