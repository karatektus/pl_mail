<?php

declare(strict_types=1);

namespace App\Controller\Task;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\DTO\Task\TaskDraft;
use App\Domain\DTO\Task\TaskPaneQuery;
use App\Domain\Enum\Task\TaskView;
use App\Domain\Exception\TaskListReadOnlyException;
use App\Domain\Helper\InlineDisposition;
use App\Entity\Calendar\Calendar;
use App\Entity\Task\Task;
use App\Entity\Task\TaskAttachment;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncTaskListMessage;
use App\Repository\Task\TaskListRepository;
use App\Repository\Task\TaskRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Demo\DemoMode;
use App\Service\Task\TaskAttachmentStorage;
use App\Service\Task\TaskListProvisioner;
use App\Service\Task\TaskPaneReader;
use App\Service\Task\TaskWriter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tasks pane: its one page, and every change made from it.
 *
 * A change made inside the pane's frame (quick add, a tick) answers with a
 * redirect back to the pane, which Turbo follows inside the frame. A change made
 * in the modal cannot do that, because the modal is its own frame at the body —
 * see _event_modal.html.twig on why a dialog never renders inside a pane — so it
 * answers with a stream that points the pane back at where it was.
 */
#[Route('/tasks', name: 'app_tasks_')]
#[IsGranted('ROLE_USER')]
final class TaskController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly TaskRepository         $tasks,
        private readonly TaskListRepository     $lists,
        private readonly TaskPaneReader         $reader,
        private readonly TaskListProvisioner    $provisioner,
        private readonly TaskWriter             $writer,
        private readonly CalendarTimeResolver   $time,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface    $translator,
        private readonly MessageBusInterface    $bus,
        private readonly TaskAttachmentStorage  $files,
        private readonly DemoMode               $demo,
    ) {}

    /**
     * Three answers from one action: the frame for a navigation inside the pane,
     * the pane's body for the sub-request that draws it open with the mailbox,
     * and the whole page otherwise — what a phone gets, where there is no dock.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Written before the read rather than after it. The read asks the table for
        // the lists, so a default still waiting for a flush was missing from the
        // pane the first time anybody opened it, and quick add had no id to post. A
        // GET that writes once in a user's whole life is the cost.
        $this->provisioner->defaultFor($this->currentUser());
        $this->em->flush();

        $pane = $this->reader->read($this->currentUser(), TaskPaneQuery::fromParameters($request->query->all()));

        $frameOnly = 'tasks-pane-frame' === $request->headers->get('Turbo-Frame')
            || true === $request->attributes->getBoolean('_tasks_pane');

        return $this->render($frameOnly ? 'tasks/_pane_frame.html.twig' : 'tasks/index.html.twig', ['pane' => $pane]);
    }

    /**
     * Remembers whether the pane is docked, and how wide.
     * CalendarController::paneState()'s reasons all apply: written on release,
     * never re-rendering, either field optional, clamped here.
     */
    #[Route('/pane-state', name: 'pane_state', methods: ['POST'])]
    public function paneState(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'tasks_pane_state');

        $user = $this->currentUser();

        if (true === $request->request->has('open')) {
            $user->setSetting(User::SETTING_TASKS_PANE_OPEN, $request->request->getBoolean('open'));
        }

        if (true === $request->request->has('width')) {
            $user->setSetting(User::SETTING_TASKS_PANE_WIDTH, max(
                User::TASKS_PANE_MIN_WIDTH,
                min(User::TASKS_PANE_MAX_WIDTH, $request->request->getInt('width')),
            ));
        }

        $this->em->flush();

        return $this->json(['open' => $user->tasksPaneOpen, 'width' => $user->tasksPaneWidth]);
    }

    /** Enter in the pane's field: a title, and whichever options were opened under it. */
    #[Route('/quick-add', name: 'quick_add', methods: ['POST'])]
    public function quickAdd(Request $request): Response
    {
        $this->assertCsrf($request, 'tasks_quick_add');

        $user  = $this->currentUser();
        $query = TaskPaneQuery::fromParameters($request->request->all());
        $title = trim($request->request->getString('title'));

        if ('' !== $title) {
            $list = $this->listFor($request->request->getInt('listId'));

            // A task added on My Day is due today, or it would leave the view
            // it was typed into in the same moment. A date somebody chose is theirs.
            $dueDate = $request->request->getString('dueDate');

            if ('' === $dueDate && TaskView::MyDay === $query->view) {
                $dueDate = (new DateTimeImmutable('now', $this->time->zoneFor($user)))->format('Y-m-d');
            }

            try {
                $this->writer->create($user, $list, new TaskDraft(
                    $title,
                    dueDate: $dueDate,
                    recurrence: $request->request->getString('recurrence'),
                    reminder: $request->request->getString('reminder'),
                ));
                $this->em->flush();
                $this->syncSoon($list);
                $this->addFlash('success', $this->translator->trans('tasks.added'));
            } catch (TaskListReadOnlyException) {
                $this->addFlash('error', $this->translator->trans('tasks.read_only'));
            }
        }

        return $this->redirectToPane($query);
    }

    #[Route('/{id}/toggle', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, Task $task): Response
    {
        $this->assertCsrf($request, 'tasks_toggle');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $task);

        try {
            if (true === $task->status->isOpen()) {
                $this->writer->complete($task);

                // Still open after a tick is a repeating task on its next date, and
                // saying "completed" over a row that is still there would read as a
                // tick that did not take.
                $message = match (true) {
                    true === $task->status->isOpen() => 'tasks.advanced',
                    null !== $task->recurrenceRule && true === $task->taskList->remoteAdvancesRepeats() => 'tasks.advanced_remote',
                    default => 'tasks.completed',
                };
            } else {
                $message = true === $task->isOccurrence() ? 'tasks.occurrence_undone' : 'tasks.reopened';

                $this->writer->reopen($task);
            }

            $this->em->flush();
            $this->syncSoon($task->taskList);
            $this->addFlash('success', $this->translator->trans($message));
        } catch (TaskListReadOnlyException) {
            $this->addFlash('error', $this->translator->trans('tasks.read_only'));
        }

        return $this->redirectToPane(TaskPaneQuery::fromParameters($request->request->all()));
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $query = TaskPaneQuery::fromParameters($request->query->all());

        return $this->renderEditor(null, $query, $this->listFor((int) $query->listId));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Request $request, Task $task): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $task);

        return $this->renderEditor($task, TaskPaneQuery::fromParameters($request->query->all()), $task->taskList);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->assertCsrf($request, 'tasks_editor');

        $user  = $this->currentUser();
        $query = TaskPaneQuery::fromParameters($request->request->all());
        $draft = new TaskDraft(
            $request->request->getString('title'),
            $request->request->getString('notes'),
            $request->request->getString('dueDate'),
            $request->request->getString('dueTime'),
            // Null when the field was not on the form: a step's dialog has no
            // repeat, and that must not read as "stop repeating".
            $request->request->has('recurrence') ? $request->request->getString('recurrence') : null,
            $request->request->has('reminder') ? $request->request->getString('reminder') : null,
        );

        $task = null;

        if (0 !== $request->request->getInt('taskId')) {
            $task = $this->tasks->find($request->request->getInt('taskId'));

            if (null === $task) {
                throw $this->createNotFoundException();
            }

            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $task);
        }

        $list = null === $task ? $this->listFor($request->request->getInt('listId')) : $task->taskList;

        $posted = $request->request->all();

        if ('' === trim($draft->title)) {
            return $this->renderEditor($task, $query, $list, $this->translator->trans('tasks.editor.title_required'), posted: $posted);
        }

        // Refused before anything is written, and with what was typed put back, so a
        // file over the limit does not cost the rest of the dialog.
        $file = $this->postedFile($request);

        if (null !== $file && (false === $file->isValid() || $file->getSize() > TaskAttachment::MAX_BYTES)) {
            return $this->renderEditor($task, $query, $list, $this->translator->trans('tasks.attachments.too_large'), posted: $posted);
        }

        // Another of this user's lists, for a task being moved there. One that is
        // not theirs is no list, and the task stays where it is.
        $movedFrom = null;
        $target    = null === $task ? null : $this->lists->findOwned($user, $request->request->getInt('listId'));

        try {
            if (null === $task) {
                $saved = $this->writer->create($user, $list, $draft);
            } else {
                $saved = $task;

                $this->writer->update($task, $draft);

                if (null !== $target && $target !== $task->taskList) {
                    $movedFrom = $task->taskList;
                    $this->writer->move($task, $target);
                }
            }

            // An empty field is what the dialog always keeps one of, not a step. A
            // step and a finished entry are offered no steps and no files, so a post
            // naming some is not one the dialog made.
            if (false === $saved->isStep() && false === $saved->isOccurrence()) {
                // By this task's own steps, so an id posted for anybody else's is never read.
                $stepTitles = $request->request->all('stepTitles');

                foreach ($saved->liveSteps() as $step) {
                    $title = $stepTitles[(string) $step->id] ?? null;

                    if (true === is_string($title)) {
                        $this->writer->rename($step, $title);
                    }
                }

                // Every row posts both, the empty ones too, so a tick is found by position.
                $done = $request->request->all('stepsDone');

                foreach ($request->request->all('steps') as $index => $title) {
                    if (true === is_string($title) && '' !== trim($title)) {
                        $step = $this->writer->addStep($saved, $title);

                        if ('1' === ($done[$index] ?? null)) {
                            $this->writer->complete($step);
                        }
                    }
                }

                if (null !== $file) {
                    $this->writer->attach($saved, $file);
                }
            }

            $this->em->flush();
            $this->syncSoon($saved->taskList);

            if (null !== $movedFrom) {
                $this->syncSoon($movedFrom);
            }
        } catch (TaskListReadOnlyException) {
            return $this->renderEditor($task, $query, $list, $this->translator->trans('tasks.read_only'), posted: $posted);
        }

        return $this->renderPaneStream($query, match (true) {
            null === $task      => 'tasks.added',
            null !== $movedFrom => 'tasks.moved',
            default             => 'tasks.saved',
        });
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Task $task): Response
    {
        $this->assertCsrf($request, 'tasks_editor');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $task);

        $query = TaskPaneQuery::fromParameters($request->request->all());

        try {
            $list = $task->taskList;

            $this->writer->delete($task);
            $this->em->flush();
            $this->syncSoon($list);
        } catch (TaskListReadOnlyException) {
            return $this->renderEditor($task, $query, $task->taskList, $this->translator->trans('tasks.read_only'));
        }

        return $this->renderPaneStream($query, 'tasks.deleted');
    }

    // ── Steps ─────────────────────────────────────────────────────────────
    //
    // Adding one is the editor's own save.

    /**
     * A tick that is already on screen: see tasks--step-tick. It says which way it
     * goes rather than "the other way", because that is what the person saw and meant,
     * whatever another device did to the step since this page was drawn.
     *
     * A refusal is an error rather than a flash, since it has a tick to take back.
     */
    #[Route('/step/{id}/tick', name: 'step_tick', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function stepTick(Request $request, Task $step): Response
    {
        $this->assertCsrf($request, 'tasks_toggle');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $step);

        if (null === $step->parent) {
            throw $this->createNotFoundException();
        }

        $done = $request->request->getBoolean('done');

        try {
            if ($done === $step->status->isOpen()) {
                if (true === $done) {
                    $this->writer->complete($step);
                } else {
                    $this->writer->reopen($step);
                }
            }

            $this->em->flush();
            $this->syncSoon($step->taskList);
        } catch (TaskListReadOnlyException) {
            throw $this->createAccessDeniedException($this->translator->trans('tasks.read_only'));
        }

        return $this->renderTurboStream('tasks/_step_ticked.stream.html.twig', [
            'message' => $this->translator->trans(true === $done ? 'tasks.step_completed' : 'tasks.step_reopened'),
            'task'    => $step->parent,
        ]);
    }

    // This one stays in the dialog: changeInDialog().

    #[Route('/step/{id}/delete', name: 'step_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function stepDelete(Request $request, Task $step): Response
    {
        $this->assertCsrf($request, 'tasks_editor');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $step);

        return $this->changeInDialog($request, $step->parent ?? $step, fn () => $this->writer->delete($step));
    }

    // ── Attachments ───────────────────────────────────────────────────────

    /**
     * A kept file, to its owner only. Always a download, under a type of no meaning
     * and the sandbox every attachment carries: the type and the bytes are the
     * uploader's, and this is the application's origin. Behind the login, which is
     * why another client gets a link it has to sign in to follow.
     */
    #[Route('/attachment/{id}', name: 'attachment_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function attachmentDownload(TaskAttachment $attachment): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $attachment);

        if (null === $attachment->storedName || false === is_file($this->files->path($attachment->storedName))) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($this->files->path($attachment->storedName));
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', InlineDisposition::SANDBOX_CSP);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            InlineDisposition::headerFilename($attachment->filename, 'attachment'),
        );

        return $response;
    }

    #[Route('/attachment/{id}/delete', name: 'attachment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function attachmentDelete(Request $request, TaskAttachment $attachment): Response
    {
        $this->assertCsrf($request, 'tasks_editor');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $attachment);

        return $this->changeInDialog($request, $attachment->task, fn () => $this->writer->detach($attachment));
    }

    // ── Lists ─────────────────────────────────────────────────────────────
    //
    // All in one dialog, opened from the gear in the pane: see tasks/_lists.html.twig.

    #[Route('/lists', name: 'lists', methods: ['GET'])]
    public function lists(Request $request): Response
    {
        return $this->renderLists(TaskPaneQuery::fromParameters($request->query->all()));
    }

    #[Route('/list/new', name: 'list_new', methods: ['GET'])]
    public function listNew(Request $request): Response
    {
        return $this->renderListEditor(null, TaskPaneQuery::fromParameters($request->query->all()));
    }

    #[Route('/list/{id}/edit', name: 'list_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function listEdit(Request $request, TaskList $list): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $list);

        return $this->renderListEditor($list, TaskPaneQuery::fromParameters($request->query->all()));
    }

    #[Route('/list/save', name: 'list_save', methods: ['POST'])]
    public function listSave(Request $request): Response
    {
        $this->assertCsrf($request, 'tasks_list_editor');

        $query  = TaskPaneQuery::fromParameters($request->request->all());
        $listId = $request->request->getInt('listId');
        $list   = 0 === $listId ? new TaskList() : $this->lists->find($listId);

        if (null === $list) {
            throw $this->createNotFoundException();
        }

        if (0 !== $listId) {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $list);
        }

        $name = mb_substr(trim($request->request->getString('name')), 0, 120);

        if ('' === $name) {
            return $this->renderListEditor(0 === $listId ? null : $list, $query, $this->translator->trans('tasks.lists.name_required'));
        }

        $color = $request->request->getString('color');

        $list->color = true === in_array($color, Calendar::COLORS, true) ? $color : $list->color;

        // A synced list's name is the remote's, and the next pull would put it
        // back; its colour is plMail's own, which is why that stays editable.
        if (true === $list->isManagedLocally()) {
            $list->name = $name;
        }

        if (0 === $listId) {
            $list->usr = $this->currentUser();
            $this->em->persist($list);
        }

        $this->em->flush();

        return $this->renderLists($query, 'tasks.lists.saved');
    }

    /**
     * Deleting a list takes its tasks with it, through the foreign key.
     *
     * Refused for the default, which is where a task with nowhere else to go
     * lands, and for a synced list, whose existence is the remote's — a mirror
     * deleted here would only be there again after the next discovery.
     */
    /** Where new tasks land from now on, when nothing else was picked. */
    #[Route('/list/{id}/landing', name: 'list_landing', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function listLanding(Request $request, TaskList $list): Response
    {
        $this->assertCsrf($request, 'tasks_list_editor');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $list);

        if (true === $list->isReadOnly) {
            throw $this->createAccessDeniedException('A read-only list can take no new task.');
        }

        $this->currentUser()->setSetting(User::SETTING_TASKS_ADD_TO, $list->id);
        $this->em->flush();

        return $this->renderLists(TaskPaneQuery::fromParameters($request->request->all()), 'tasks.lists.landing_set', ['%name%' => $list->name]);
    }

    #[Route('/list/{id}/delete', name: 'list_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function listDelete(Request $request, TaskList $list): Response
    {
        $this->assertCsrf($request, 'tasks_list_editor');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $list);

        if (true === $list->isDefault || false === $list->isManagedLocally()) {
            throw $this->createAccessDeniedException('This list cannot be deleted.');
        }

        // The pane was showing the list that is gone: it shows them all instead.
        $query = TaskPaneQuery::fromParameters($request->request->all());

        if ($query->listId === $list->id) {
            $query = $query->withList(null);
        }

        $this->em->remove($list);
        $this->em->flush();

        return $this->renderLists($query, 'tasks.lists.deleted');
    }

    // ---------------------------------------------------------------- helpers

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** One of this user's lists, or where their new tasks land when the id names none of them. */
    private function listFor(int $id): TaskList
    {
        return $this->lists->findOwned($this->currentUser(), $id)
            ?? $this->provisioner->landingFor($this->currentUser());
    }

    /**
     * Sends the change on now rather than at the next sweep, which can be a quarter
     * of an hour away. After the flush, so the worker finds what it was sent for.
     */
    private function syncSoon(TaskList $list): void
    {
        if (true === $list->isSynced()) {
            $this->bus->dispatch(new SyncTaskListMessage((int) $list->id));
        }
    }

    /**
     * The file the dialog carries, if any. Never on a public demo, whose disk has
     * nothing to reap one from: the field is not offered there, so this is a post
     * the dialog did not make.
     */
    private function postedFile(Request $request): ?UploadedFile
    {
        $file = $request->files->get('file');

        return $file instanceof UploadedFile && false === $this->demo->isEnabled() ? $file : null;
    }

    private function redirectToPane(TaskPaneQuery $query): Response
    {
        return $this->redirectToRoute('app_tasks_index', $query->toParameters(), Response::HTTP_SEE_OTHER);
    }

    /**
     * One thing done from inside the dialog, which stays open and answers as itself.
     *
     * Everything the form sent comes back in it, since every button here posts the
     * whole form: see tasks/_editor.html.twig. That includes a file chosen and not
     * yet uploaded, which a browser cannot put back in a field, so it is kept now
     * rather than lost to a tick beside it.
     *
     * The pane is told too, whose row counts these and which a dialog closed with
     * Cancel would otherwise leave stale.
     */
    private function changeInDialog(Request $request, Task $task, callable $change): Response
    {
        $query  = TaskPaneQuery::fromParameters($request->request->all());
        $posted = $request->request->all();
        $list   = $task->taskList;
        $file   = $this->postedFile($request);
        $error  = null;

        try {
            $change();

            if (null !== $file) {
                if (false === $file->isValid() || $file->getSize() > TaskAttachment::MAX_BYTES) {
                    $error = $this->translator->trans('tasks.attachments.too_large');
                } else {
                    $this->writer->attach($task, $file);
                }
            }

            $this->em->flush();
            $this->syncSoon($list);
        } catch (TaskListReadOnlyException) {
            return $this->renderEditor($task, $query, $list, $this->translator->trans('tasks.read_only'), posted: $posted);
        }

        return $this->renderEditor($task, $query, $list, $error, $this->generateUrl('app_tasks_index', $query->toParameters()), $posted);
    }

    /** @param array<string, mixed> $posted what the form sent, to put back */
    private function renderEditor(?Task $task, TaskPaneQuery $query, TaskList $list, ?string $error = null, ?string $paneUrl = null, array $posted = []): Response
    {
        return $this->render('tasks/_editor.html.twig', [
            'paneUrl' => $paneUrl,
            'posted'  => $posted,
            'task'    => $task,
            'list'    => $list,
            'query'   => $query,
            'error'   => $error,
            'zone'    => $this->time->zoneFor($this->currentUser()),
            // Where a task can be put: a read-only list can take nothing.
            'lists'   => array_values(array_filter(
                $this->lists->findForUser($this->currentUser()),
                static fn (TaskList $each): bool => false === $each->isReadOnly,
            )),
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The Lists dialog, telling the pane about a change when there was one.
     *
     * @param array<string, string> $messageParams
     */
    private function renderLists(TaskPaneQuery $query, ?string $messageKey = null, array $messageParams = []): Response
    {
        return $this->render('tasks/_lists.html.twig', [
            'lists'   => $this->lists->findForUser($this->currentUser()),
            'landing' => $this->provisioner->landingFor($this->currentUser()),
            'query'   => $query,
            'message' => null === $messageKey ? null : $this->translator->trans($messageKey, $messageParams),
            'paneUrl' => null === $messageKey ? null : $this->generateUrl('app_tasks_index', $query->toParameters()),
        ]);
    }

    private function renderListEditor(?TaskList $list, TaskPaneQuery $query, ?string $error = null): Response
    {
        return $this->render('tasks/_list_editor.html.twig', [
            'list'   => $list,
            'query'  => $query,
            'error'  => $error,
            'colors' => Calendar::COLORS,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function renderPaneStream(TaskPaneQuery $query, string $messageKey): Response
    {
        return $this->renderTurboStream('tasks/_saved.stream.html.twig', [
            'message' => $this->translator->trans($messageKey),
            'paneUrl' => $this->generateUrl('app_tasks_index', $query->toParameters()),
        ]);
    }
}
