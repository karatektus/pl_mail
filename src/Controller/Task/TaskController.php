<?php

declare(strict_types=1);

namespace App\Controller\Task;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\DTO\Task\TaskDraft;
use App\Domain\DTO\Task\TaskEdit;
use App\Domain\DTO\Task\TaskPaneQuery;
use App\Domain\Enum\Task\TaskView;
use App\Domain\Exception\CalendarSyncException;
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
use App\Service\Task\TaskEditorSave;
use App\Service\Task\TaskListProvisioner;
use App\Service\Task\TaskListTransfer;
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
        private readonly TaskListTransfer       $transfer,
        private readonly TaskEditorSave         $editor,
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
     * One page of a list's finished tasks, into the Completed group's frame when it
     * is opened, and into the page frame below it for the next. See TaskPane.
     */
    #[Route('/completed', name: 'completed', methods: ['GET'])]
    public function completed(Request $request): Response
    {
        $query = TaskPaneQuery::fromParameters($request->query->all());
        $page  = $this->reader->completedPage($this->currentUser(), $query, $request->query->getInt('offset'));

        return $this->render('tasks/_completed_page.html.twig', [
            'pane'   => $page['pane'],
            'tasks'  => $page['tasks'],
            'next'   => $page['next'],
            'offset' => $request->query->getInt('offset'),
        ]);
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
        $target = null === $task ? null : $this->lists->findOwned($user, $request->request->getInt('listId'));
        $edit   = new TaskEdit(
            $draft,
            $request->request->all('stepTitles'),
            $request->request->all('steps'),
            $request->request->all('stepsDone'),
            $file,
        );

        try {
            $done = $this->editor->save($user, $task, $list, $target, $edit);
        } catch (TaskListReadOnlyException) {
            return $this->renderEditor($task, $query, $list, $this->translator->trans('tasks.read_only'), posted: $posted);
        }

        $this->syncSoon($done->task->taskList);

        if (null !== $done->movedFrom) {
            $this->syncSoon($done->movedFrom);
        }

        return $this->renderPaneStream($query, match (true) {
            null === $task            => 'tasks.added',
            null !== $done->movedFrom => 'tasks.moved',
            default                   => 'tasks.saved',
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

        // A task is deleted from its own dialog, which closes; here the dialog
        // stays open on the task, and would draw one that is gone.
        if (null === $step->parent) {
            throw $this->createNotFoundException();
        }

        return $this->changeInDialog($request, $step->parent, fn () => $this->writer->delete($step));
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
            $list->usr       = $this->currentUser();
            $list->sortOrder = $this->lists->nextSortOrder($this->currentUser());
            $this->em->persist($list);
        }

        $this->em->flush();

        return $this->renderLists($query, 'tasks.lists.saved');
    }

    /**
     * The order of the lists, as arranged in Lists: the whole order, as JSON, so a
     * repeated request lands on the same arrangement. Built from the user's own
     * rows, so an id that is not theirs finds nothing, and a list the request left
     * out keeps its relative place at the end: settings--account-order's endpoint,
     * AccountController::reorder(), reasons the same way.
     */
    #[Route('/list/order', name: 'list_order', methods: ['POST'])]
    public function listOrder(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'tasks_list_order');

        $ids = $request->toArray()['ids'] ?? null;

        if (false === is_array($ids)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $byId = [];

        foreach ($this->lists->findForUser($this->currentUser()) as $list) {
            $byId[(int) $list->id] = $list;
        }

        $ordered = [];

        foreach (array_map('intval', $ids) as $id) {
            if (true === array_key_exists($id, $byId)) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        foreach ([...$ordered, ...array_values($byId)] as $position => $list) {
            $list->sortOrder = $position;
        }

        $this->em->flush();

        return $this->json(['ok' => true]);
    }

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

    /**
     * Deleting a list takes its tasks with it, through the foreign key.
     *
     * Refused for the default, which is where a task with nowhere else to go
     * lands, and for a synced list, whose existence is the remote's — a mirror
     * deleted here would only be there again after the next discovery.
     */
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

    /**
     * Taking a synced list's tasks into plMail for good: see TaskListTransfer.
     * Its own view in the Lists dialog, which says what moves and what does not
     * before anything does.
     */
    #[Route('/list/{id}/transfer', name: 'list_transfer', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function listTransfer(Request $request, TaskList $list): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $list);

        if (false === $list->isSynced()) {
            throw $this->createNotFoundException();
        }

        return $this->renderListTransfer($list, TaskPaneQuery::fromParameters($request->query->all()));
    }

    /**
     * Asks the server for its latest first. When it cannot be reached, nothing
     * moves and the view offers to take what is here instead (`stale`), which is
     * the only way left once the server is gone for good.
     */
    #[Route('/list/{id}/transfer', name: 'list_transfer_run', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function listTransferRun(Request $request, TaskList $list): Response
    {
        $this->assertCsrf($request, 'tasks_list_editor');
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $list);

        if (false === $list->isSynced()) {
            throw $this->createNotFoundException();
        }

        $query    = TaskPaneQuery::fromParameters($request->request->all());
        $targetId = $request->request->getInt('target');
        $target   = null;

        if (0 !== $targetId) {
            $target = $this->lists->findOwned($this->currentUser(), $targetId);

            if (null === $target || false === $target->isManagedLocally() || true === $target->isReadOnly) {
                throw $this->createNotFoundException();
            }
        }

        $fromId = $list->id;

        try {
            $done = $this->transfer->transfer($list, $target, false === $request->request->getBoolean('stale'));
        } catch (CalendarSyncException $e) {
            return $this->renderListTransfer($list, $query, $e->getMessage(), $targetId);
        }

        // The pane was showing the list that is gone: it shows where the tasks went.
        if ($query->listId === $fromId) {
            $query = $query->withList($done->to->id);
        }

        return $this->renderLists($query, 'tasks.lists.transferred', [
            '%count%' => $done->moved,
            '%name%'  => $done->to->name,
        ]);
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
     * @param array<string, string|int> $messageParams
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

    /** @param string|null $failure why the server could not be asked, which offers to go ahead without it */
    private function renderListTransfer(TaskList $list, TaskPaneQuery $query, ?string $failure = null, int $target = 0): Response
    {
        $user = $this->currentUser();

        return $this->render('tasks/_list_transfer.html.twig', [
            'list'     => $list,
            'query'    => $query,
            'failure'  => $failure,
            'zone'     => $this->time->zoneFor($user),
            'target'   => $target,
            'open'     => $this->tasks->countForList($user, $list, false),
            'finished' => $this->tasks->countForList($user, $list, true),
            'targets'  => array_values(array_filter(
                $this->lists->findForUser($user),
                static fn (TaskList $each): bool => true === $each->isManagedLocally() && false === $each->isReadOnly,
            )),
        ], new Response(status: null === $failure ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function renderPaneStream(TaskPaneQuery $query, string $messageKey): Response
    {
        return $this->renderTurboStream('tasks/_saved.stream.html.twig', [
            'message' => $this->translator->trans($messageKey),
            'paneUrl' => $this->generateUrl('app_tasks_index', $query->toParameters()),
        ]);
    }
}
