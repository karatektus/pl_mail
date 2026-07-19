<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Label;
use App\Form\LabelType;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class LabelController extends AbstractController
{
    public function __construct(
        private readonly LabelRepository        $labelRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $label = new Label();
        $form  = $this->createForm(LabelType::class, $label, [
            'user' => $this->getUser(),
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $duplicate = $this->labelRepository->findOneChildByName(
                $label->account,
                $label->parent,
                (string) $label->name,
            );

            if (null !== $duplicate) {
                $form->get('name')->addError(
                    new FormError('label.error.duplicate')
                );
            } else {
                $this->em->persist($label);
                $this->em->flush();

                return $this->render('label/_saved.stream.html.twig', [
                    'label' => $label,
                ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
            }
        }

        return $this->render('label/_form.html.twig', [
            'form'  => $form,
            'label' => $label,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Label $label, Request $request): Response
    {
        $this->assertOwnedUserLabel($label);

        $form = $this->createForm(LabelType::class, $label, [
            'user'          => $this->getUser(),
            'edited_label'  => $label,
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $label->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();

            return $this->render('label/_saved.stream.html.twig', [
                'label' => $label,
            ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
        }

        return $this->render('label/_form.html.twig', [
            'form'  => $form,
            'label' => $label,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Label $label): Response
    {
        $this->assertOwnedUserLabel($label);

        // parent FK cascades — children go with it. message_label /
        // thread_label rows cascade too; the messages themselves stay.
        $this->em->remove($label);
        $this->em->flush();

        return $this->render('label/_deleted.stream.html.twig', [
            'labelId' => $label->id,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function assertOwnedUserLabel(Label $label): void
    {
        if ($label->account?->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (true === $label->isSystem) {
            throw $this->createAccessDeniedException();
        }
    }
}
