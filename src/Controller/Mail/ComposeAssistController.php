<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Ai\WritingAssistant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Help me write this."
 *
 * NO DRAFT ID IN THE ROUTE, DELIBERATELY
 * ──────────────────────────────────────
 * A composer opens with no message id — one is minted by the first autosave —
 * so a URL built at render time with an id in it is stale in exactly the window
 * where somebody is most likely to ask for help: the empty one they just
 * opened. Everything this needs travels in the body instead, and nothing is
 * saved as a side effect. The draft is the browser's business; this only
 * answers.
 *
 * JSON, NOT A TURBO STREAM
 * ────────────────────────
 * The answer goes into a contenteditable at a caret position only the browser
 * knows, and it has to be insertable without disturbing a selection or a
 * pending autosave. A stream would replace a region and lose both.
 *
 * THE SERVER MAKES THE CALL, AND THAT IS NOT AN IMPLEMENTATION DETAIL
 * ──────────────────────────────────────────────────────────────────
 * `connect-src 'self'` is enforced in production, so a browser cannot reach the
 * model host at all — and should not: the endpoint is an address on the
 * operator's private network and putting it in a page hands it to every script
 * that page ever loads.
 */
#[Route('/compose/assist', name: 'app_compose_assist')]
#[IsGranted('IS_AUTHENTICATED')]
final class ComposeAssistController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly WritingAssistant  $assistant,
        private readonly MessageRepository $messages,
    ) {
    }

    #[Route('', name: '', methods: ['POST'])]
    public function assist(Request $request): Response
    {
        // Same token the composer's other posts carry. This spends time on
        // another machine on the user's behalf and returns text that lands in
        // their draft, so it is not a thing to leave open to a cross-site post.
        $this->assertCsrf($request, 'ajax');

        if (false === $this->assistant->isAvailable()) {
            // 409 rather than 403: nothing is forbidden, the feature is simply
            // not switched on — and the browser should stop offering it rather
            // than report an error the user can do nothing about.
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_CONFLICT);
        }

        $task = WritingTask::tryFrom((string) $request->request->get('task', ''));

        if (null === $task) {
            return new JsonResponse(['error' => 'unknown_task'], Response::HTTP_BAD_REQUEST);
        }

        $text = $this->assistant->write(
            $task,
            (string) $request->request->get('draft', ''),
            $this->context($request),
            (string) $request->request->get('subject', ''),
        );

        if (null === $text) {
            // The host is down, the model said nothing usable, or there was
            // nothing to work from. One answer for all of them, because the
            // browser's response to each is the same: say so, and leave the
            // draft exactly as it was.
            return new JsonResponse(['error' => 'no_answer'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['text' => $text]);
    }

    /**
     * The message being replied to, read from the server rather than the page.
     *
     * The browser sends an id; the body comes out of the database. Trusting the
     * page for it would let anything that can post here choose what the model
     * is told — with the answer landing in the user's own draft, which is
     * exactly the shape of thing that should not be steerable from outside.
     *
     * Ownership is checked, so an id belonging to somebody else is refused
     * rather than summarised.
     */
    private function context(Request $request): ?string
    {
        $id = $request->request->get('inReplyTo');

        if (null === $id || '' === trim((string) $id)) {
            return null;
        }

        $message = $this->messages->find((int) $id);

        if (false === $message instanceof Message) {
            return null;
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $message->bodyText;
    }
}
