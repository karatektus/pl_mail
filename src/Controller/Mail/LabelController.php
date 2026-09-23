<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Entity\Label\Label;
use App\Entity\User\User;
use App\Form\LabelType;
use App\Repository\Label\LabelRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Label\LabelNotifier;
use App\Service\Label\LabelStructurePropagator;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    public function __construct(
        private readonly LabelRepository        $labelRepository,
        private readonly EntityManagerInterface $em,
        private readonly LabelStructurePropagator $structurePropagator,
        private readonly MailChangeRecorder     $changes,
        private readonly TranslatorInterface    $translator,
        private readonly LabelNotifier          $labelNotifier,
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

            $duplicate = $this->labelRepository->findOneChildByName(
                $this->getUser(),
                $label->parent,
                (string) $label->name,
            );

            if (null !== $duplicate) {
                // Translated here, not in the theme: form_errors renders
                // error.message verbatim, and validator-produced errors arrive
                // already translated — re-translating them in the shared theme
                // would be the riskier fix.
                $form->get('name')->addError(
                    new FormError($this->translator->trans('label.error.duplicate'))
                );
            } else {
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
            $this->em->flush();

            $this->structurePropagator->renamed($label, $previousFullName);
            $this->changes->labelChanged($label);
            $this->em->flush();

            return $this->labelListsStream('label.updated');
        }

        return $this->renderForm($form, $label);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Label $label): Response
    {
        $this->assertOwnedUserLabel($label);

        // This is the destructive action of the three and was the only one
        // without a CSRF check.
        $this->assertCsrf($request, 'label-delete' . $label->id);

        // Dispatch before removal: the propagator reads the remote id and
        // name off the bindings, and there is nothing to read afterwards.
        $this->structurePropagator->deleted($label);
        $this->changes->labelDeleted($label);

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

        return $this->labelListsStream($toastMessage);
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
    private function labelListsStream(?string $toastMessage = null): Response
    {
        $user   = $this->currentUser();
        $labels = $this->labelRepository->findForUserTreeOrdered($user);

        $lists = $this->renderView('label/_lists.stream.html.twig', [
            'toastMessage' => null,
            'labels'       => $labels,
        ]);

        $this->labelNotifier->publishLabelsChanged($user, $lists);

        if (null === $toastMessage) {
            $body = $lists;
        } else {
            $body = $this->renderView('label/_lists.stream.html.twig', [
                'toastMessage' => $toastMessage,
                'labels'       => $labels,
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
