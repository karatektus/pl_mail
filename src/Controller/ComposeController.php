<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Form\ComposeType;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/compose', name: 'app_compose_')]
class ComposeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailboxRepository $mailboxRepository,
    ) {}

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        $message = new Message();

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    #[Route('/draft', name: 'draft', methods: ['POST'])]
    public function draft(Request $request): Response
    {
        $message = new Message();

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'], // no 'send' group — To is optional
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->persistDraft($form, $message);

            return $this->render('compose/_window.html.twig', [
                'form' => $form,
                'message' => $message,
                'saved' => true,
            ]);
        }

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    #[Route('/send', name: 'send', methods: ['POST'])]
    public function send(Request $request): Response
    {
        $message = new Message();

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default', 'send'], // To is required here
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->persistDraft($form, $message);

            // TODO: dispatch SendMailMessage

            $this->addFlash('success', 'Message sent.');
            return $this->redirectToRoute('app_mail_sent');
        }

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    private function persistDraft(FormInterface $form, Message $message): void
    {
        /** @var Account $account */
        $account = $form->get('account')->getData();

        $draftsMailbox = $this->mailboxRepository->findDraftMailboxForAccount($account);

        if (!$draftsMailbox instanceof Mailbox) {
            throw new \RuntimeException('No Drafts mailbox found for the selected account.');
        }

        $now = new \DateTimeImmutable();

        $message
            ->setMailbox($draftsMailbox)
            ->setFromAddress($account->getEmail() ?? $account->getUsername())
            ->setFromName($account->getName())
            ->setHasAttachments(false)
            ->setCreatedAt($now)
            ->setUpdatedAt($now)
        ;

        $this->em->persist($message);
        $this->em->flush();
    }
}
