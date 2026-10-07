<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Entity\User\User;
use App\Service\Mail\StatusUndoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Undo in the toast after an archive, a label change or a "Move to".
 *
 * A controller of its own for the reason BulkStatusController is one: the
 * other two status controllers are prefixed with the thing they act on — one
 * conversation in the path, or a selection in the body — and an undo is
 * addressed by neither. It names an earlier action, by the token that action's
 * toast was given, and the server already knows which messages that was.
 *
 * Nothing is rendered for the list. The rows that left it have to come back in
 * their sorted places, with the pager and the counts they change, and the only
 * thing that knows all of that is the list itself — so the client re-reads the
 * list frame when this answers. See mail--status-undo.
 */
#[Route('/status/undo', name: 'app_status_undo_')]
#[IsGranted('ROLE_USER')]
final class StatusUndoController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly StatusUndoService $undo,
    ) {
    }

    #[Route('/{token}', name: 'run', methods: ['POST'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function undo(Request $request, string $token): Response
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        // Null is a token that names nothing any more — used once already, or
        // pushed out by newer actions. Said rather than answered with a quiet
        // 200, because the person pressed a button that promised to put
        // something back and it has not been put back.
        $restored = $this->undo->restore($token, $user);

        return $this->renderTurboStream('thread/status/_undone.stream.html.twig', [
            'restored' => null !== $restored,
        ]);
    }
}
