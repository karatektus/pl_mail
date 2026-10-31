<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\Enum\Job\JobKind;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Form\LabelDeleteType;
use App\Infrastructure\Messaging\Message\MakeLabelFolderMessage;
use App\Infrastructure\Messaging\Message\MakeLabelTagMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Label\LabelDeletion;
use App\Service\Label\LabelFolderMaker;
use App\Service\Label\LabelPlaces;
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
use Throwable;

/**
 * The label dialog's per-account controls (#221). "Make it a folder": a dialog
 * that says what will move, then the folder and a job for the mail. "Make it a
 * label": a dialog that asks where the mail goes, then a job that moves it and
 * deletes the folder.
 */
#[Route('/labels', name: 'app_label_')]
#[IsGranted('IS_AUTHENTICATED')]
final class LabelFolderController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly LabelFolderMaker       $maker,
        private readonly MessageRepository      $messages,
        private readonly LabelRepository        $labels,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly TranslatorInterface    $translator,
        private readonly LabelPlaces            $places,
        private readonly LabelDeletion          $deletion,
    ) {}

    #[Route('/{label}/folder-on/{account}', name: 'make_folder_confirm', methods: ['GET'])]
    public function confirm(Label $label, Account $account): Response
    {
        $this->assertCanMake($label, $account);

        return $this->dialog($label, $account);
    }

    #[Route('/{label}/folder-on/{account}', name: 'make_folder', methods: ['POST'])]
    public function make(Request $request, Label $label, Account $account): Response
    {
        $this->assertCanMake($label, $account);
        $this->assertCsrf($request, 'label-make-folder' . $label->id . '-' . $account->id);

        try {
            $this->maker->make($label, $account);
        } catch (Throwable $e) {
            return $this->dialog($label, $account, $e->getMessage());
        }

        if (0 === $this->messages->countToFileIntoFolder((int) $account->id, (int) $label->id)) {
            return new Response($this->renderView('label/_lists.stream.html.twig', [
                'toastMessage'   => 'label.form.places.make_folder_done',
                'labels'         => $this->labels->findForUserTreeOrdered($this->currentUser()),
                'refreshSidebar' => true,
            ]), headers: ['Content-Type' => 'text/vnd.turbo-stream.html']);
        }

        $job = new BackgroundJob($this->currentUser(), JobKind::MoveTo);

        $job->view = [
            'scope'      => 'label',
            'value'      => (string) $label->id,
            'unreadOnly' => false,
            'labelId'    => $label->id,
            'accountId'  => $account->id,
        ];

        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new MakeLabelFolderMessage((int) $job->id));

        return $this->renderTurboStream('mail/_job_started.stream.html.twig', ['job' => $job]);
    }

    #[Route('/{label}/tag-on/{account}', name: 'make_tag_confirm', methods: ['GET'])]
    public function confirmTag(Label $label, Account $account): Response
    {
        $this->assertCanBecomeTag($label, $account);

        return $this->tagDialog($label, $account, $this->tagForm($label, $account));
    }

    #[Route('/{label}/tag-on/{account}', name: 'make_tag', methods: ['POST'])]
    public function makeTag(Request $request, Label $label, Account $account): Response
    {
        $this->assertCanBecomeTag($label, $account);
        $this->assertCsrf($request, 'label-make-tag' . $label->id . '-' . $account->id);

        $form = $this->tagForm($label, $account);

        if (0 < count($form->getErrors())) {
            return $this->tagDialog($label, $account, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $destination = null;

        if (true === $form->has('destination')) {
            $form->handleRequest($request);

            $value       = $form->get('destination')->getData();
            $destination = $this->destinations($label, $account)[is_string($value) ? $value : ''] ?? null;

            if (null === $destination) {
                $form->get('destination')->addError(new FormError($this->translator->trans('label.delete.destination_required')));

                return $this->tagDialog($label, $account, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $job = new BackgroundJob($this->currentUser(), JobKind::MoveTo);

        $job->view = [
            'scope'         => 'label',
            'value'         => (string) $label->id,
            'unreadOnly'    => false,
            'labelId'       => $label->id,
            'accountId'     => $account->id,
            'destinationId' => ($destination ?? $this->destinations($label, $account)['archive'])->id,
        ];

        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new MakeLabelTagMessage((int) $job->id));

        return $this->renderTurboStream('mail/_job_started.stream.html.twig', ['job' => $job]);
    }

    /**
     * The system places, and the labels that are a folder on THIS account: its
     * mail cannot move into a folder another account has.
     *
     * @return array<string, Label>
     */
    private function destinations(Label $label, Account $account): array
    {
        return array_filter(
            $this->deletion->destinations($label, $this->currentUser()),
            fn (Label $candidate): bool => null !== $candidate->role || true === $this->maker->isFolder($candidate, $account),
        );
    }

    private function tagForm(Label $label, Account $account): FormInterface
    {
        $choices = [];

        if (0 < $this->messages->countToFileIntoFolder((int) $account->id, (int) $label->id)) {
            foreach ($this->destinations($label, $account) as $value => $destination) {
                $choices[$value] = null === $destination->role
                    ? $destination->fullName
                    : $this->translator->trans('sidebar.nav.' . $destination->role->value);
            }
        }

        $form = $this->createForm(LabelDeleteType::class, null, [
            'action'       => $this->generateUrl('app_label_make_tag', ['label' => $label->id, 'account' => $account->id]),
            'destinations' => $choices,
        ]);

        // Mail still arriving has no message to move yet, and the server would
        // report the folder full for as long as the job waits.
        if (true === $label->bindingFor($account)?->mailbox?->isImporting()) {
            $form->addError(new FormError($this->translator->trans('label.delete.still_importing', ['%name%' => $label->fullName])));
        }

        return $form;
    }

    private function tagDialog(Label $label, Account $account, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('label/_make_tag.html.twig', [
            'label'     => $label,
            'account'   => $account,
            'form'      => $form,
            'mailCount' => $this->messages->countToFileIntoFolder((int) $account->id, (int) $label->id),
        ], new Response(status: $status));
    }

    private function assertCanBecomeTag(Label $label, Account $account): void
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $label);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        if (false === $this->places->canBecomeTag($label, $account)) {
            throw $this->createNotFoundException();
        }
    }

    private function dialog(Label $label, Account $account, ?string $error = null): Response
    {
        $form = $this->createForm(LabelDeleteType::class, null, [
            'action'       => $this->generateUrl('app_label_make_folder', ['label' => $label->id, 'account' => $account->id]),
            'destinations' => [],
        ]);

        if (null !== $error) {
            $form->addError(new FormError($this->translator->trans('label.form.places.make_folder_failed', ['%error%' => $error])));
        }

        return $this->render('label/_make_folder.html.twig', [
            'label'       => $label,
            'account'     => $account,
            'form'        => $form,
            'mailCount'   => $this->messages->countToFileIntoFolder((int) $account->id, (int) $label->id),
            'hasCategory' => null !== $label->bindingFor($account)?->graphCategoryId,
            'parentToo'   => null !== $label->parent && null === $label->parent->role && false === $this->maker->isFolder($label->parent, $account),
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function assertCanMake(Label $label, Account $account): void
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $label);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        if (false === $this->maker->canMake($label, $account)) {
            throw $this->createNotFoundException();
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
