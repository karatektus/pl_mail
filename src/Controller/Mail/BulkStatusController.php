<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Job\BackgroundJob;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\RunBulkStatusMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\MoveToService;
use App\Service\Mail\StatusUndoService;
use App\Service\Mail\ThreadSnoozeService;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulk mail actions — one request for a whole selection.
 *
 * Its own controller rather than another route on ThreadStatusController,
 * because that one is prefixed `/status/{type}/{id}` and every route on it is
 * about ONE conversation named in the path. A bulk action is about a set named
 * in the body, and hanging it off that prefix would have produced
 * `/status/thread/17/bulk/archive` — a URL that names a conversation it does
 * not act on.
 */
#[Route('/status/bulk', name: 'app_status_bulk_')]
#[IsGranted('ROLE_USER')]
final class BulkStatusController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    /**
     * The actions that refuse a whole-view selection.
     *
     * Every one of them is a drop target and nothing else: a drag names the
     * rows it is carrying, so "everything in this view" is not a shape any of
     * them can arrive in. Spelled as a list rather than inline so the guard
     * below and this reasoning stay in one place as they are joined.
     *
     * `label` has a second caller now — the toolbar's label menu, which sends
     * the ticked rows — and still no whole-view one. `move-to` is absent on
     * purpose: it is a toolbar button, the toolbar can select a whole view,
     * and JobKind::MoveTo runs it.
     *
     * @var list<string>
     */
    private const array EXPLICIT_ONLY = ['move', 'label', 'category', 'star'];

    public function __construct(
        private readonly MessageThreadRepository $threadRepository,
        private readonly LabelRepository         $labelRepository,
        private readonly ThreadStatusUpdater     $status,
        private readonly EntityManagerInterface  $entityManager,
        private readonly MessageBusInterface     $bus,
        private readonly ThreadSnoozeService     $snoozeService,
        private readonly MoveToService           $moveTo,
        private readonly StatusUndoService       $undo,
    ) {
    }

    /**
     * One request for a whole selection, and for a whole view.
     *
     * The toolbar used to post once per conversation, in parallel. That is fine
     * for the fifty rows a page can hold and impossible for what was asked for
     * — "select everything in this folder" over a bin holding two hundred would
     * have opened two hundred connections, and a real mailbox is worse.
     *
     * It also could not answer the other half of what the list needs. Each of
     * those responses redrew its own row, so the pager kept saying "1–5 of 5"
     * over four rows and an emptied list never showed its empty state: nothing
     * in a per-row stream knows what the list as a whole now looks like. This
     * answers with the list frame, once, which is the only thing that does.
     *
     * @see ListViewResolver for why the view arrives as a named scope rather
     *      than as the URL the user is on — it is resolved by
     *      RunBulkStatusHandler now, where the work happens.
     */
    #[Route('/{action}', name: 'run', methods: ['POST'], requirements: ['action' => 'archive|trash|read|restore|snooze|move|move-to|label|category|star'])]
    public function bulk(Request $request, string $action): Response
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        // Only a drag posts move, label, category or star, and a drag carries
        // the rows it picked up. There is no "select every conversation in this view and
        // drop it" gesture, so the whole-view path below has no caller for
        // these and no JobKind to run them under — JobKind::forAction()
        // would throw for them, deep inside startJob(). Refused here instead,
        // where the reason is legible.
        if (true === ($body['all'] ?? false) && true === in_array($action, self::EXPLICIT_ONLY, true)) {
            throw $this->createAccessDeniedException(
                sprintf('The "%s" action takes an explicit list of conversations.', $action),
            );
        }

        // Resolved and refused ahead of the whole-view hand-off below as well
        // as ahead of the inline path, because this is the one payload-carrying
        // action that can take either: a job started for a label that is
        // somebody else's would fail in a worker, minutes later, in a log.
        $target = null;

        if ('move-to' === $action) {
            // The Inbox, Spam and Trash rows name a role rather than an id —
            // see MoveToService::systemTarget() for why they cannot rely on
            // one. Everything else is a label the user made, by id.
            $target = '' !== (string) ($body['role'] ?? '')
                ? $this->moveTo->systemTarget($user, (string) $body['role'])
                : $this->labelRepository->find((int) ($body['labelId'] ?? 0));

            if (null === $target) {
                throw $this->createAccessDeniedException('Unknown destination label.');
            }

            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $target);

            // The picker offers the Inbox, the user's labels, Spam and Trash.
            // MoveToService::accepts() is that list for a caller that is not
            // the picker.
            if (false === $this->moveTo->accepts($target)) {
                throw $this->createAccessDeniedException(
                    sprintf('Mail cannot be moved to %s.', $target->role?->value),
                );
            }
        }

        // A WHOLE VIEW GOES TO A WORKER. An explicit list of ids does not.
        //
        // The two are different sizes by construction: a list of ids comes from
        // rows on screen and is bounded by the page, while a view selection is
        // however much mail the user has. Doing the second one inline is what
        // produced `Maximum execution time of 30 seconds exceeded` on a mailbox
        // with five thousand unread — every thread hydrated, every message
        // loaded, an ownership check apiece, inside a request with thirty
        // seconds to live. The user got a broken page and no way to tell how
        // much of it had happened.
        //
        // The small case stays inline deliberately. It is the common one, it
        // finishes in milliseconds, and answering it with "started" instead of
        // the result would make every ordinary archive feel slower.
        if (true === ($body['all'] ?? false)) {
            return $this->startJob($user, $action, $body, $target?->id);
        }

        // THE DROP PAYLOADS ARE READ AND REFUSED BEFORE ANY WORK IS DONE.
        //
        // Both used to be resolved down where they are used, which put them
        // after the "nothing was selected" early return — so a request naming a
        // category that does not exist answered 200 as long as its id list was
        // empty. That is the difference between a route that refuses bad input
        // and one that refuses it only when it happens to have something to do.
        $category    = null;
        $destination = null;

        if ('category' === $action) {
            $category = MessageCategory::tryFrom((string) ($body['category'] ?? ''));

            // tryFrom and a refusal, where MailController falls back to Primary
            // on the same input. The difference is what the two are: `?tab=` is
            // a URL somebody may have mistyped, and showing them the inbox is
            // the kind answer. This is a write, and "filed it under Primary
            // because I did not recognise what you asked for" is not something
            // an interface should do quietly.
            if (null === $category) {
                throw $this->createAccessDeniedException('Unknown inbox category.');
            }
        }

        if ('move' === $action) {
            $destination = $this->labelRepository->find((int) ($body['labelId'] ?? 0));

            if (null === $destination) {
                throw $this->createAccessDeniedException('Unknown destination folder.');
            }

            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $destination);

            // Sent, Drafts and Snoozed are not places mail can be filed —
            // LabelRole::acceptsMoves() holds the whole argument. The sidebar
            // renders no drop target for them, and this is the other half of
            // that: a rule stated only in a template is a rule the next caller
            // does not have.
            $role = $destination->role;

            if (null !== $role && false === $role->acceptsMoves()) {
                throw $this->createAccessDeniedException(
                    sprintf('Mail cannot be moved into %s.', $role->value),
                );
            }
        }

        $threads = $this->threadRepository->findBy(['id' => array_map(intval(...), (array) ($body['ids'] ?? []))]);

        // Grouped by account, and that is not an optimisation.
        // ThreadStatusUpdater resolves the destination label and its IMAP
        // folder from the FIRST message's account — which is right for every
        // caller that names one conversation, and wrong for a selection
        // spanning two mailboxes: everything would be filed into the first
        // account's Archive. One call per account keeps each resolution
        // correct, and there are rarely more than three.
        $byAccount = [];

        // The same messages, ungrouped: what an Undo has to remember is the
        // selection as a whole, whichever account each part of it is on.
        $selected = [];

        foreach ($threads as $thread) {
            // Every thread, not just the first: the ids arrive from a browser
            // and a selection can be edited before it is posted.
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $thread);

            foreach ($thread->messages as $message) {
                $byAccount[(int) $message->account?->id][] = $message;
                $selected[]                                 = $message;
            }
        }

        if ([] === $byAccount) {
            return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
                'count'   => 0,
                'threads' => [],
                'leaves'  => false,
            ]);
        }

        // MOVE TO — the target on, the label of the list it was started from
        // off. The request names that list and nothing more; which label comes
        // off is MoveToService::plan()'s answer, not the client's.
        if ('move-to' === $action) {
            $scope = (string) ($body['scope'] ?? '');
            $value = (string) ($body['value'] ?? '');
            $move  = $this->moveTo->plan($user, $target, $scope, $value);

            // Moving a conversation to the list it is already in. The picker
            // hides that entry; answered as "nothing happened" rather than
            // refused, because that is what it is.
            if (true === $move->isNoop) {
                return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
                    'count'   => 0,
                    'threads' => [],
                    'leaves'  => false,
                ]);
            }

            $undoToken = $this->undo->remember($selected);

            $this->moveTo->move($threads, $move);

            return $this->renderTurboStream('thread/status/_moved_to.stream.html.twig', [
                'count'     => count($threads),
                'threads'   => $threads,
                'label'     => $target,
                'staying'   => array_values(array_filter(
                    $threads,
                    fn ($thread): bool => $this->moveTo->staysInView($thread, $user, $scope, $value),
                )),
                'undoToken' => $undoToken,
            ]);
        }

        // SNOOZE IS PER CONVERSATION, through the same service the single-row
        // route and Thread/set use — it moves the Inbox label off, queues the
        // provider archive and remembers when to undo it. Not the per-account
        // grouping below: the service resolves each thread's own account.
        //
        // Both directions leave the list they were started from: a snoozed
        // conversation leaves the inbox, a woken one leaves Snoozed.
        if ('snooze' === $action) {
            $until = self::snoozeUntil($body);

            foreach ($threads as $thread) {
                if (null === $until) {
                    $this->snoozeService->wake($thread);
                } else {
                    $this->snoozeService->snooze($thread, $until);
                }
            }

            return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
                'count'   => count($threads),
                'threads' => $threads,
                'leaves'  => true,
            ]);
        }

        // STARRING, ONLY EVER ON — the drop on Starred, its one caller.
        //
        // Per conversation like the category below: the Starred view lists
        // threads by their own starredAt, so that is the column that answers
        // whether one is starred already. Not the row's star button, which
        // toggles — dropping a starred conversation onto Starred must leave
        // it starred. It stays in the list it came from, redrawn with its star.
        if ('star' === $action) {
            $this->status->starThreads($threads);

            return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
                'count'   => count($threads),
                'threads' => $threads,
                'leaves'  => false,
            ]);
        }

        // A CATEGORY IS A FACT ABOUT A CONVERSATION, NOT ABOUT ITS MESSAGES.
        //
        // Every other action here mutates labels, which live on messages, so
        // they all go through the per-account grouping below. The inbox tabs
        // filter on MessageThread::$category — one column, on the row the tab
        // strip counts — so this one is applied to the threads directly and
        // returns before the grouping it has no use for.
        if ('category' === $action) {
            foreach ($threads as $thread) {
                $this->status->setCategory($thread, $category);
            }

            return $this->renderTurboStream('thread/status/_category.stream.html.twig', [
                'count'    => count($threads),
                'threads'  => $threads,
                'category' => $category,
            ]);
        }

        // ATTACHING A LABEL, WHICH IS NOT MOVING INTO ONE.
        //
        // A drop on a folder and a drop on a label are two gestures now, and
        // the difference is which half of the sidebar was dropped on: the rows
        // above LABELS are places, so landing there means the conversation IS
        // there and nowhere else; a label is a thing a conversation wears, so
        // landing on one adds it and leaves the mail where it was.
        //
        // The rule is a property of the TARGET rather than of the label, and it
        // has to be: the same custom label appears twice in the sidebar, once
        // under LABELS meaning "everywhere" and once under an account meaning
        // "that account's folder". Which one was dropped on is the question,
        // and only the drop knows.
        //
        // System labels are refused, exactly as /status/{type}/{id}/label
        // refuses them: attaching Inbox or Trash without detaching what the
        // mail already carries is a half-move, and leaves a conversation
        // claiming to be in two places. Those rows send `move` instead.
        if ('label' === $action) {
            $label = $this->labelRepository->find((int) ($body['labelId'] ?? 0));

            if (null === $label) {
                throw $this->createAccessDeniedException('Unknown label.');
            }

            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $label);

            if (true === $label->isSystem) {
                throw $this->createAccessDeniedException('System state is moved into, not labelled with.');
            }

            // Absent means attach, which is all a drop has ever asked for. The
            // toolbar's label menu is the caller that sends it: it used to
            // post once per ticked row to /status/thread/{id}/label, which was
            // one toast and one Undo per conversation the moment that route
            // gained them.
            $attach    = false !== ($body['attach'] ?? true);
            $undoToken = $this->undo->remember($selected);

            foreach ($byAccount as $messages) {
                $this->status->applyLabel($messages, $label, $attach);
            }

            return $this->renderTurboStream('thread/status/_labelled.stream.html.twig', [
                'count'     => count($threads),
                'threads'   => $threads,
                'label'     => $label,
                'attach'    => $attach,
                'undoToken' => $undoToken,
            ]);
        }

        // A branch of its own rather than a fifth arm in the match below,
        // because a match arm reading `$this->status->move($messages,
        // $destination)` makes a promise about a variable the arms around it
        // know nothing about, and only the reader can see that the two
        // conditions are the same condition.
        if ('move' === $action) {
            $undoToken = $this->undo->remember($selected);

            foreach ($byAccount as $messages) {
                $this->status->move($messages, $destination);
            }

            // Its own template rather than a flag on the shared one, and the
            // difference is a toast. Every other action here is a button whose
            // label already said what it would do and whose result is somewhere
            // the user can go and look at. A move is a drop — aimed by pointer
            // at a row a couple of dozen pixels tall — that ends with the
            // conversation gone from the list and nothing on screen saying
            // where it went.
            return $this->renderTurboStream('thread/status/_move.stream.html.twig', [
                'count'     => count($threads),
                'threads'   => $threads,
                'label'     => $destination,
                'undoToken' => $undoToken,
            ]);
        }

        // Archive is the one of these four with an Undo, so it is the one whose
        // "before" is written down. Trash has the bin and Restore; read has
        // the button beside it.
        $undoToken = 'archive' === $action
            ? $this->undo->remember($selected)
            : null;

        foreach ($byAccount as $messages) {
            match ($action) {
                'archive' => $this->status->archive($messages),
                'trash'   => $this->status->trash($messages),
                'restore' => $this->status->restore($messages),
                'read'    => $this->status->markRead($messages, true === ($body['read'] ?? true)),
            // Unreachable: the route requirement above is the allow-list, so a
            // fifth action cannot arrive here without being added there too.
            // Stated rather than assumed, because the day someone widens the
            // requirement and forgets this arm, a silent no-op is the worst
            // possible answer for an action that says it deleted things.
            //
            // move, move-to, label, category and star are not here on purpose:
            // each is handled by a branch of its own above, which is where the
            // payload they need is resolved.
                default   => throw $this->createNotFoundException(sprintf('Unknown bulk action "%s".', $action)),
            };
        }

        return $this->renderTurboStream('thread/status/_bulk.stream.html.twig', [
            'count'   => count($threads),
            'threads' => $threads,
            // Whether the conversation is still in the list it was acted on
            // from. Archive, trash and restore all move it somewhere else;
            // marking read leaves it exactly where it was and only changes how
            // it draws.
            'leaves'    => 'read' !== $action,
            'undoToken' => $undoToken,
            'undoToast' => 'toast.archived',
        ]);
    }
    /**
     * Hand a whole-view action to a worker and say so.
     *
     * The job row is created here rather than in the handler so the answer can
     * name it: the page gets an indicator with something in it immediately,
     * instead of a spinner waiting for a worker to pick the envelope up.
     *
     * @param array<string, mixed> $body
     * @param int|null             $labelId the resolved target of a move-to;
     *                             the id rather than what the body said,
     *                             because the body may have named a role
     */
    private function startJob(User $user, string $action, array $body, ?int $labelId = null): Response
    {
        $until = 'snooze' === $action ? self::snoozeUntil($body) : null;
        $job   = new BackgroundJob($user, JobKind::forAction($action, true === ($body['read'] ?? true), null === $until));

        $job->view = [
            'scope'      => (string) ($body['scope'] ?? ''),
            'value'      => (string) ($body['value'] ?? ''),
            'unreadOnly' => true === ($body['unreadOnly'] ?? false),
        ];

        // The two actions with a payload the worker needs. On the job with the
        // view rather than in the envelope, for the reason the view is there.
        if ('snooze' === $action) {
            $job->view['until'] = $until?->format(DATE_ATOM);
        }

        // Already resolved, owned and accepted by bulk() before it got here.
        if ('move-to' === $action) {
            $job->view['labelId'] = $labelId;
        }

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->bus->dispatch(new RunBulkStatusMessage((int) $job->id));

        return $this->renderTurboStream('mail/_job_started.stream.html.twig', [
            'job' => $job,
        ]);
    }

    /**
     * The wake time a snooze asked for, or null to wake the selection.
     *
     * The same reading as ThreadStatusController::snooze(), fallback included:
     * an unparseable date from the browser's snooze menu becomes "in 1 day"
     * rather than a refusal, exactly as it does for one row.
     *
     * @param array<string, mixed> $body
     */
    private static function snoozeUntil(array $body): ?DateTimeImmutable
    {
        if (null === ($body['until'] ?? null)) {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $body['until']);
        } catch (\Exception) {
            return new DateTimeImmutable('+1 day');
        }
    }
}
