<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\Enum\Job\JobKind;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\User\User;
use App\Form\LabelDeleteType;
use App\Form\LabelType;
use App\Infrastructure\Messaging\Message\DeleteLabelMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\Label\LabelRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Label\LabelDeletion;
use App\Service\Label\LabelNotifier;
use App\Service\Label\LabelStructurePropagator;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CRUD for user labels. Rendered inside the existing modal flow (same
 * pattern as account management).
 *
 * Remote propagation is intentionally lazy and best-effort:
 *   - Gmail: the label is created remotely on FIRST USE by
 *     ApplyGmailLabelsHandler::ensureRemoteLabel() — no API call here.
 *   - IMAP: no folder is created on label creation; per the location-label
 *     model a folder is only relevant when a message's location label is
 *     replaced, and destination resolution simply skips labels without a
 *     backing folder. Incoming folder sync links folders to labels when
 *     they appear.
 */
#[Route('/labels', name: 'app_label_')]
#[IsGranted('IS_AUTHENTICATED')]
final class LabelController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly LabelRepository        $labelRepository,
        private readonly EntityManagerInterface $em,
        private readonly LabelStructurePropagator $structurePropagator,
        private readonly MailChangeRecorder     $changes,
        private readonly TranslatorInterface    $translator,
        private readonly LabelNotifier          $labelNotifier,
        private readonly LabelDeletion          $deletion,
        private readonly BackgroundJobRepository $jobs,
        private readonly MessageBusInterface    $bus,
    ) {}

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $label = new Label();
        $form  = $this->createForm(LabelType::class, $label, [
            'action' => $this->generateUrl('app_label_new'),
            'user'   => $this->getUser(),
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $label->usr = $this->getUser();

            // Refused, and the error is on the field: fall through to the
            // re-render below, which answers 422.
            if (false === $this->refuseTakenName($form, $label)) {
                $this->em->persist($label);
                $this->em->flush();

                // Same seam Mailbox/set uses, so a label made in the browser
                // and one made from a JMAP client behave identically.
                //
                // No JMAP state to record and nothing to propagate yet: a new
                // label has no bindings, so it exists on no account until it is
                // first applied to mail. LabelResolver::binding() records the
                // Mailbox creation at that point.
                $this->structurePropagator->created($label);
                $this->em->flush();

                return $this->labelListsStream('label.created');
            }
        }

        return $this->renderForm($form, $label);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Label $label, Request $request): Response
    {
        $this->assertOwnedUserLabel($label);

        // Captured before the form binds, and it is the only chance: an Exchange
        // master category has no id but its display name, so a rename that has
        // lost the old one has nothing to address at the provider. See
        // LabelStructurePropagator::renamed().
        $previousFullName = $label->fullName;

        $form = $this->createForm(LabelType::class, $label, [
            'action'        => $this->generateUrl('app_label_edit', ['id' => $label->id]),
            'user'          => $this->getUser(),
            'edited_label'  => $label,
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            if (true === $this->refuseTakenName($form, $label, $label)) {
                // The form has already written the refused name onto the
                // managed entity. Nothing here flushes it, but "nothing
                // flushes" is a property of this request today and not of the
                // object: put the stored values back before anything can.
                $response = $this->renderForm($form, $label);
                $this->em->refresh($label);

                return $response;
            }

            $this->em->flush();

            $this->structurePropagator->renamed($label, $previousFullName);
            $this->changes->labelChanged($label);
            $this->em->flush();

            return $this->labelListsStream('label.updated');
        }

        return $this->renderForm($form, $label);
    }

    /**
     * The delete dialog: what deleting this label will do, and — when it is a
     * folder on a mail server with mail in it — where that mail goes.
     *
     * A dialog rather than the browser's confirm(), which is what both delete
     * buttons used: a confirm can ask "are you sure" and cannot ask "where to".
     * Shown for every label, so the two buttons behave the same whether or not
     * there is a question to ask.
     */
    #[Route('/{id}/delete', name: 'delete_confirm', methods: ['GET'])]
    public function deleteConfirm(Label $label): Response
    {
        $this->assertOwnedUserLabel($label);

        return $this->renderDeleteDialog($label, $this->deleteForm($label));
    }

    /**
     * Delete a label — straight away when it is only a tag, through a job when
     * it is a folder on a mail server.
     *
     * The split is issue #40. A label that is a folder on IMAP or Exchange was
     * removed here and left standing there, and the next sync put it back. The
     * folder has to go too, and a folder cannot go with mail in it without the
     * mail going with it — so its mail is moved to the place the person picked
     * first, and only then is the folder deleted, then the label. That is
     * minutes of work on a big folder and has to wait for the server, so it
     * is DeleteLabelHandler's, and this answers with the jobs indicator.
     *
     * A tag — a Gmail label, an Exchange category, a label that exists only
     * here — holds no mail, and goes as it always did.
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Label $label): Response
    {
        $this->assertOwnedUserLabel($label);

        // This is the destructive action of the three and was the only one
        // without a CSRF check.
        $this->assertCsrf($request, 'label-delete' . $label->id);

        if (true === $this->deletion->isOnAServer($label)) {
            return $this->startFolderDelete($request, $label);
        }

        // Dispatch before removal: the propagator reads the remote id and
        // name off the bindings, and there is nothing to read afterwards.
        //
        // For every label in the subtree, not only this one. The cascade below
        // removes the nested labels here, and a provider does not: Gmail
        // deleting "Work" leaves "Work/Invoices" standing, and the next sync
        // brings it back.
        foreach ($this->deletion->subtree($label) as $member) {
            $this->structurePropagator->deleted($member);
            $this->changes->labelDeleted($member);
        }

        // parent FK cascades — children go with it, and so do the bindings.
        // message_label / thread_label rows cascade too; the messages stay.
        //
        // Deleting here removes the label from EVERY account, which is what
        // the unified sidebar shows. JMAP's Mailbox/set destroy is the
        // per-account operation and only drops one binding.
        $this->em->remove($label);
        $this->em->flush();

        return $this->labelListsStream('label.deleted');
    }

    /**
     * The label lists, as the turbo-stream every label change answers with.
     *
     * For a tab told by LabelNotifier::publishLabelsStale() that its lists are
     * out of date — a label removed by a background job, where nobody was
     * signed in to render them for. Read-only, and this user's own labels.
     */
    #[Route('/lists', name: 'lists', methods: ['GET'])]
    public function lists(): Response
    {
        return $this->labelListsStream();
    }

    /**
     * Show/hide a label in the sidebar. Unlike the CRUD actions this also
     * allows SYSTEM labels — that's how the hidden Archive label becomes
     * user-enableable.
     */
    #[Route('/{id}/toggle-visibility', name: 'toggle_visibility', methods: ['POST'])]
    public function toggleVisibility(Request $request, Label $label): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $label);

        $this->assertCsrf($request, 'label-visibility' . $label->id);

        $label->isVisible = false === $label->isVisible;
        $this->em->flush();

        if (true === $label->isVisible) {
            $toastMessage = 'label.visibility.shown';
        } else {
            $toastMessage = 'label.visibility.hidden';
        }

        $this->changes->labelChanged($label);
        $this->em->flush();

        // A system row lives in the hand-written block above the label list,
        // which none of the three regions covers — so the eye flipped in the
        // settings list and the sidebar beside it went on showing the row
        // until the next full page load. The sidebar is re-rendered whole, and
        // only for this tab: the toggle exists on the settings page alone, so
        // this tab has no disclosure open for the re-render to shut, and
        // another tab sitting in Archive would have its "More" snapped closed.
        return $this->labelListsStream($toastMessage, $label->isSystem);
    }
    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Every label mutation refreshes the same three regions — the desktop
     * sidebar, the mobile drawer and the settings list — so they all return
     * this. Streams whose target is absent are no-ops, which is what lets one
     * response serve the sidebar modal and the settings page alike.
     *
     * ── And every OTHER tab of this user gets them too ───────────────────────
     * The lists are published on the user's Mercure topic on the way out. Until
     * this, the refresh reached exactly the tab that had pressed the button: a
     * second tab, or the same account open on a laptop, kept showing the old
     * name until something else made it re-render — which, since v0.2.34
     * answers a folder click with the list frame alone, means until its next
     * mailbox sync. See {@see LabelNotifier} for why the markup travels rather
     * than a "go and look" nudge.
     *
     * ── Rendered twice, deliberately ────────────────────────────────────────
     * The two copies differ by exactly one thing and it matters: the TOAST.
     * "Label created." belongs to the person who created it. Published, it
     * would pop unprompted in every other tab of theirs, and in this one it
     * would arrive a second time beside the one the response already carried.
     * So the shared copy is rendered without it.
     *
     * The second render costs no database round trips, MEASURED: a rename on a
     * user with ten labels in a two-level tree is 20 statements with one render
     * and 20 with two. The tree is read once here and handed to both, and
     * everything the sidebar macros ask SidebarCounts for is memoised per
     * request — so what is paid twice is Twig over an already-loaded tree,
     * which is the cheap half.
     *
     * Applying the published copy in the tab that caused it is harmless, and
     * that is why no echo-suppression exists: the three streams are `replace`
     * actions carrying identical markup, so the second pass swaps like for
     * like. Section and tree disclosure states survive it because they are
     * rendered from the stored preference rather than from the live DOM, which
     * is the same reason a navigation has always been able to rebuild them.
     */
    private function labelListsStream(?string $toastMessage = null, bool $refreshSidebar = false): Response
    {
        $user   = $this->currentUser();
        $labels = $this->labelRepository->findForUserTreeOrdered($user);

        $lists = $this->renderView('label/_lists.stream.html.twig', [
            'toastMessage' => null,
            'labels'       => $labels,
        ]);

        $this->labelNotifier->publishLabelsChanged($user, $lists);

        if (null === $toastMessage && false === $refreshSidebar) {
            $body = $lists;
        } else {
            $body = $this->renderView('label/_lists.stream.html.twig', [
                'toastMessage'   => $toastMessage,
                'labels'         => $labels,
                'refreshSidebar' => $refreshSidebar,
            ]);
        }

        return new Response($body, headers: ['Content-Type' => 'text/vnd.turbo-stream.html']);
    }

    /**
     * Narrowed to User — IS_AUTHENTICATED is not a type, and the topic a label
     * change is published on is built from this user's id. A helper rather
     * than #[CurrentUser] because the private stream builder needs it too.
     */
    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * A submitted-but-invalid form must come back as 422, not 200:
     * modal_controller closes the dialog on any successful turbo:submit-end,
     * so a 200 here would swallow the errors and look like a silent save.
     */
    /**
     * Put an error on the form when the name is one the user already has, and
     * say whether it did.
     *
     * The rule is LabelRepository::findNameConflict() — the same call
     * Mailbox/set makes, so a name the apps are refused is refused here and
     * the other way round. This only decides where the sentence goes. It used
     * to be an exact-match lookup written out in new() alone: a rename was
     * never checked at all, and "work" beside "Work" passed both.
     *
     * $itself is the label being edited, which is not in its own way.
     *
     * Translated here, not in the theme: form_errors renders error.message
     * verbatim, and validator-produced errors arrive already translated —
     * re-translating them in the shared theme would be the riskier fix.
     */
    private function refuseTakenName(FormInterface $form, Label $label, ?Label $itself = null): bool
    {
        $taken = $this->labelRepository->findNameConflict(
            $this->getUser(),
            $label->parent,
            (string) $label->name,
            $itself,
        );

        if (null === $taken) {
            return false;
        }

        // A system label's form has no name field; the error then belongs to
        // the form as a whole.
        $field = true === $form->has('name') ? $form->get('name') : $form;

        $field->addError(new FormError($this->translator->trans('label.error.duplicate')));

        return true;
    }

    /**
     * Hand a folder-backed label to DeleteLabelHandler, once there is a place
     * for its mail to go.
     */
    private function startFolderDelete(Request $request, Label $label): Response
    {
        $user = $this->currentUser();
        $form = $this->deleteForm($label);

        // Pressed twice, or from two tabs. The first job is already moving the
        // mail; a second would move whatever the first had not reached yet
        // somewhere else, if the two dialogs were answered differently.
        foreach ($this->jobs->findVisibleForUser($user) as $job) {
            if (JobKind::DeleteLabel === $job->kind && true === $job->isActive() && $label->id === ($job->view['labelId'] ?? null)) {
                return $this->renderTurboStream('_partials/_toast.stream.html.twig', [
                    'message' => $this->translator->trans('label.delete.already_running', ['%name%' => $label->fullName]),
                    'type'    => 'info',
                ]);
            }
        }

        // Mail still arriving from the server: the count the dialog showed is
        // short, and the folder would not empty. See LabelDeletion::stillImporting().
        if (0 < count($form->getErrors())) {
            return $this->renderDeleteDialog($label, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $destination = null;

        if (true === $form->has('destination')) {
            $form->handleRequest($request);

            $value       = $form->get('destination')->getData();
            $destination = $this->deletion->destination($label, $user, is_string($value) ? $value : '');

            // Blank, or a value the dialog did not offer. Either way the mail
            // has nowhere to go, and the folder is not deleted around it.
            if (null === $destination) {
                $form->get('destination')->addError(new FormError(
                    $this->translator->trans('label.delete.destination_required'),
                ));

                return $this->renderDeleteDialog($label, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $job = new BackgroundJob($user, JobKind::DeleteLabel);

        // The scope and value every job carries, so anything reading a job as
        // a view over mail reads this one as the label it is emptying.
        $job->view = [
            'scope'         => 'label',
            'value'         => (string) $label->id,
            'unreadOnly'    => false,
            'labelId'       => $label->id,
            'destinationId' => $destination?->id,
        ];

        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new DeleteLabelMessage((int) $job->id));

        return $this->renderTurboStream('mail/_job_started.stream.html.twig', [
            'job' => $job,
        ]);
    }

    /**
     * The delete dialog's form — with the destination question when there is
     * mail in the label's folders, and with nothing to fill in otherwise.
     */
    private function deleteForm(Label $label): FormInterface
    {
        $destinations = [];

        $candidates = 0 === $this->deletion->mailInFolders($label)
            ? []
            : $this->deletion->destinations($label, $this->currentUser());

        foreach ($candidates as $value => $destination) {
            $destinations[$value] = null === $destination->role
                ? $destination->fullName
                : $this->translator->trans('sidebar.nav.' . $destination->role->value);
        }

        $form = $this->createForm(LabelDeleteType::class, null, [
            'action'       => $this->generateUrl('app_label_delete', ['id' => $label->id]),
            'destinations' => $destinations,
        ]);

        // Said on the dialog itself, before anybody presses anything, and
        // refused by the route if they press Delete anyway.
        $importing = $this->deletion->stillImporting($label);

        if (null !== $importing) {
            $form->addError(new FormError(
                $this->translator->trans('label.delete.still_importing', ['%name%' => $importing]),
            ));
        }

        return $form;
    }

    private function renderDeleteDialog(Label $label, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('label/_delete.html.twig', [
            'label'     => $label,
            'form'      => $form,
            'onServer'  => $this->deletion->isOnAServer($label),
            'mailCount' => $this->deletion->mailInFolders($label),
            'nested'    => count($this->deletion->subtree($label)) - 1,
        ], new Response(status: $status));
    }

    private function renderForm(FormInterface $form, Label $label): Response
    {
        if (true === $form->isSubmitted() && false === $form->isValid()) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        } else {
            $status = Response::HTTP_OK;
        }

        return $this->render('label/_form.html.twig', [
            'form'  => $form,
            'label' => $label,
        ], new Response(status: $status));
    }

    /**
     * Owning it is not enough — it also has to be a label the user made.
     *
     * The ownership half is the voter's; what stays here is the second
     * refusal, which is not about whose label it is but about what kind: a
     * system label (Inbox, Sent, Trash…) belongs to the user like any other,
     * and still must not be renamed or deleted through the custom-label routes.
     * The eye toggle is the deliberate exception and has its own route.
     */
    private function assertOwnedUserLabel(Label $label): void
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $label);

        if (true === $label->isSystem) {
            throw $this->createAccessDeniedException();
        }
    }
}
