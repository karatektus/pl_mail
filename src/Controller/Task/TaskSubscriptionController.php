<?php

declare(strict_types=1);

namespace App\Controller\Task;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Task\TaskPaneQuery;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Integration\Integration\IntegrationRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\Task\Sync\TaskListSubscriber;
use App\Service\Task\Sync\TaskSyncDriverRegistry;
use App\Service\Task\Sync\TaskSyncSources;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which remote task lists to mirror, in the app's modal.
 *
 * Routes named `app_tasks_subscribe…`, which DemoGuardSubscriber refuses by prefix:
 * they reach out to a real server on somebody's behalf.
 *
 * A discovery that fails is rendered, not thrown: the person reading it is already
 * asking why there are no lists, and that sentence is the answer.
 */
#[Route('/tasks/subscribe', name: 'app_tasks_subscribe')]
#[IsGranted('ROLE_USER')]
final class TaskSubscriptionController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly IntegrationRepository  $integrations,
        private readonly AccountRepository      $accounts,
        private readonly TaskSyncDriverRegistry $drivers,
        private readonly TaskListSubscriber     $subscriber,
        private readonly TaskSyncSources        $sources,
        private readonly TranslatorInterface    $translator,
    ) {}

    /** Every account and connection a driver speaks for. */
    #[Route('', name: '', methods: ['GET'])]
    public function sources(): Response
    {
        return $this->render('tasks/_subscribe_sources.html.twig', ['sources' => $this->sources->forUser($this->currentUser())]);
    }

    #[Route('/{kind}/{id}', name: '_source', requirements: ['kind' => 'account|integration', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function source(Request $request, string $kind, int $id): Response
    {
        $source = $this->sourceFor($kind, $id);
        $status = Response::HTTP_OK;
        $error  = null;

        if (true === $request->isMethod('POST')) {
            $this->assertCsrf($request, 'tasks-subscribe' . $kind . $id);

            try {
                $this->subscriber->apply($this->currentUser(), $source, $this->tickedRemoteIds($request), $this->chosenColours($request));

                return $this->renderTurboStream('tasks/_saved.stream.html.twig', [
                    'message' => $this->translator->trans('tasks.subscribe.saved'),
                    'paneUrl' => $this->generateUrl('app_tasks_index', (new TaskPaneQuery())->withList(null)->toParameters()),
                ]);
            } catch (CalendarSyncException $e) {
                $error  = $e->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        $offered = [];

        if (null === $error) {
            try {
                $offered = $this->subscriber->offered($source);
            } catch (CalendarSyncException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('tasks/_subscribe.html.twig', [
            'offered'     => $offered,
            'sourceKind'  => $kind,
            'sourceId'    => $id,
            'sourceLabel' => TaskSyncSources::labelOf($source),
            'error'       => $error,
            'colors'      => Calendar::COLORS,
        ], new Response(status: $status));
    }

    // ---------------------------------------------------------------- helpers

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** Refuses what is not this user's, and what no driver speaks for. */
    private function sourceFor(string $kind, int $id): CalendarSource
    {
        $user = $this->currentUser();

        if ('account' === $kind) {
            $account = $this->accounts->find($id);
            $source  = null === $account || $account->usr !== $user ? null : CalendarSource::ofAccount($account);
        } else {
            $integration = $this->integrations->findOneForUser($user, $id);
            $source      = null === $integration ? null : CalendarSource::ofIntegration($integration);
        }

        if (null === $source || false === $this->drivers->has($source)) {
            throw $this->createAccessDeniedException();
        }

        return $source;
    }

    /**
     * A colour per remote id, of the app's own: anything else is no choice, and
     * TaskListSubscriber keeps what there is.
     *
     * @return array<string, string>
     */
    private function chosenColours(Request $request): array
    {
        return array_filter(
            $request->request->all('colors'),
            static fn (mixed $color): bool => true === in_array($color, Calendar::COLORS, true),
        );
    }

    /** @return list<string> */
    private function tickedRemoteIds(Request $request): array
    {
        $ticked = $request->request->all('remoteIds');

        return array_values(array_filter($ticked, 'is_string'));
    }
}
