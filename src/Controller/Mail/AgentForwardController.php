<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\DTO\Mail\ComposeContext;
use App\Domain\DTO\Mail\ForwardAgent;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\AgentForwarder;
use App\Service\Mail\ForwardAgents;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Forward to agent": pick one of your agents, say what you want, and the mail
 * goes to them as a forward with your note on top.
 *
 * GET renders the dialog into the shared modal frame, POST sends. One path for
 * both, as InsightReportController has it, because they are one resource seen
 * twice. The POST names the agent by address and is refused unless that
 * address is on the sender's own list, so this endpoint cannot be used to mail
 * a stranger.
 *
 * The answer to a send is a turbo-stream with the same toast and the same Undo
 * a send from the composer gets; Undo reopens the forward in the compose dock.
 * The browser half is assets/controllers/mail/agent_forward_controller.js.
 */
#[Route('/mail/forward-to-agent', name: 'app_mail_agent_forward_')]
#[IsGranted('IS_AUTHENTICATED')]
final class AgentForwardController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public const int MAX_NOTE_CHARS = 5_000;

    public function __construct(
        private readonly ForwardAgents  $agents,
        private readonly AgentForwarder $forwarder,
    ) {
    }

    #[Route('/{id}', name: 'dialog', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function dialog(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        $user   = $this->currentUser();
        $agents = $this->agents->all($user);

        return $this->render('mail/_agent_forward_dialog.html.twig', [
            'message' => $message,
            'agents'  => $agents,
            'sealed'  => array_values(array_map(
                static fn (ForwardAgent $agent): string => $agent->address,
                array_filter($agents, fn (ForwardAgent $agent): bool => $this->forwarder->sealsFor($user, $agent)),
            )),
            'refusal' => $this->forwarder->refusal($message, $user),
        ]);
    }

    #[Route('/{id}', name: 'send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(Message $message, Request $request): Response
    {
        $this->assertCsrf($request, 'agent-forward');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        $payload = json_decode($request->getContent(), true);
        $payload = true === is_array($payload) ? $payload : [];
        $user    = $this->currentUser();
        $agent   = $this->agents->find($user, (string) ($payload['agent'] ?? ''));

        if (null === $agent) {
            throw new UnprocessableEntityHttpException('Not one of your agents.');
        }

        $note    = mb_substr((string) ($payload['note'] ?? ''), 0, self::MAX_NOTE_CHARS);
        $forward = $this->forwarder->forward($message, $agent, $note, $user);

        if (null === $forward) {
            throw new UnprocessableEntityHttpException('This mail cannot be forwarded from here.');
        }

        $dock = new ComposeContext(inline: false, frame: ComposeContext::DOCK_FRAME, mode: ComposeContext::MODE_FORWARD);

        return $this->renderTurboStream('mail/_agent_forward_sent.stream.html.twig', [
            'agent'        => $agent,
            'sent'         => $forward['sent'],
            'undoUrl'      => $this->generateUrl('app_compose_mail_undo', $dock->urlParams() + ['id' => $forward['draft']->id]),
            'cancelWindow' => ComposeController::CANCEL_WINDOW_MS,
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
