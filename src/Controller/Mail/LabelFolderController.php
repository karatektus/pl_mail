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
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Label\LabelFolderMaker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * "Make it a folder" in the label dialog's per-account controls (#221): a
 * dialog that says what will move, then the folder and a job for the mail.
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
