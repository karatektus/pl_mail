<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\MoveToService;
use App\Service\Mail\SpamSenderResolver;
use App\Service\Mail\StatusUndoService;
use App\Service\Rule\SpamFilterCreator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The spam button: move one conversation to Spam, and optionally make the
 * filter that does the same to whatever its sender writes next.
 *
 * Two routes, because the button is a menu. The first renders what the menu
 * offers for this conversation — which depends on who it is from, and that is
 * worked out here rather than rendered into every row of a list of fifty. The
 * second does it.
 *
 * The move itself is "Move to → Spam" and goes through the same service, so
 * what leaves which list, what the provider is told and what an Undo puts back
 * are all exactly what they are from the picker. What this adds is the filter.
 *
 * One conversation at a time. A selection has no single sender to offer a
 * filter for; the list toolbar's own button moves it and offers nothing else.
 */
#[Route('/status/thread/{id}', name: 'app_status_spam_', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class SpamController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    private const string NO_FILTER = 'none';

    public function __construct(
        private readonly MessageThreadRepository $threads,
        private readonly SpamSenderResolver      $senders,
        private readonly SpamFilterCreator       $filters,
        private readonly MoveToService           $moveTo,
        private readonly StatusUndoService       $undo,
        private readonly TranslatorInterface     $translator,
    ) {
    }

    /** The menu's contents for this conversation. A fragment, loaded when the button is pressed. */
    #[Route('/spam-menu', name: 'menu', methods: ['GET'])]
    public function menu(int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('mail/_spam_menu_panel.html.twig', [
            'sender' => $this->senders->of($this->thread($id), $user),
        ]);
    }

    #[Route('/spam', name: 'report', methods: ['POST'])]
    public function report(Request $request, int $id): Response
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user   = $this->getUser();
        $thread = $this->thread($id);

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        $filter = (string) ($body['filter'] ?? self::NO_FILTER);

        // Refused before anything moves: a request that asked for a filter and
        // got only the move would be half obeyed, with a toast that could not
        // say which half.
        if (self::NO_FILTER !== $filter && false === in_array($filter, SpamFilterCreator::SCOPES, true)) {
            throw new BadRequestHttpException('Unknown filter.');
        }

        $sender = self::NO_FILTER === $filter ? null : $this->senders->of($thread, $user);

        if (self::NO_FILTER !== $filter && null === $sender) {
            throw new BadRequestHttpException('This conversation has no sender to filter.');
        }

        $target = $this->moveTo->systemTarget($user, 'spam');

        if (null === $target) {
            throw $this->createAccessDeniedException('No spam label.');
        }

        $scope = (string) ($body['scope'] ?? '');
        $value = (string) ($body['value'] ?? '');
        $move  = $this->moveTo->plan($user, $target, $scope, $value);

        // Pressed on mail that is already in Spam, from the Spam list. The
        // button is not drawn there; the honest answer to a caller that posts
        // anyway is that nothing happened.
        if (true === $move->isNoop) {
            return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
                'count'   => 0,
                'threads' => [],
                'leaves'  => false,
            ]);
        }

        $undoToken = $this->undo->remember($thread->messages);

        $this->moveTo->move([$thread], $move);

        $message = null;

        if (null !== $sender) {
            [$rule, $created] = $this->filters->create($user, $sender, $filter);

            // Only a rule this press made goes back with the Undo.
            if (true === $created) {
                $this->undo->alsoRemove($undoToken, $rule);
            }

            $message = $this->translator->trans('toast.spam_filtered', [
                '%sender%' => $this->filters->pattern($sender, $filter),
            ]);
        }

        return $this->renderTurboStream('thread/status/_moved_to.stream.html.twig', [
            'count'     => 1,
            'threads'   => [$thread],
            'label'     => $target,
            'staying'   => true === $this->moveTo->staysInView($thread, $user, $scope, $value) ? [$thread] : [],
            'undoToken' => $undoToken,
            'message'   => $message,
        ]);
    }

    private function thread(int $id): MessageThread
    {
        $thread = $this->threads->find($id);

        if (null === $thread) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $thread);

        return $thread;
    }
}
