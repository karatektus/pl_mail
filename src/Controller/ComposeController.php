<?php

namespace App\Controller;

use App\Domain\Enum\MessageFlag;
use App\Entity\Account;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Form\ComposeType;
use App\Message\SendMessageMessage;
use App\Repository\MailboxRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Form\FormInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/compose', name: 'app_compose_')]
class ComposeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailboxRepository $mailboxRepository,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('/new', name: 'new', methods: ['GET'])]
    #[Route('/edit/{id}', name: 'edit', methods: ['GET'])]
    public function compose(?Message $message = null): Response
    {
        if(null === $message) {
            $message = new Message();
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    #[Route('/draft', name: 'form_new', methods: ['POST'])]
    #[Route('/draft/{id}', name: 'form_edit', methods: ['POST'])]
    public function draft(Request $request, ?Message $message = null): Response
    {
        if(null === $message) {
            $message = new Message();
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'], // no 'send' group — To is optional
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
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


    #[Route('/send', name: 'mail_send', methods: ['POST'])]
    #[Route('/send/{id}', name: 'mail_send_draft', methods: ['POST'])]
    public function send(Request $request, ?Message $message): Response
    {
        if(null === $message) {
            $message = new Message();
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default', 'send'],
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->persistDraft($form, $message); // saves to DB first

            $this->bus->dispatch(
                new SendMessageMessage($message->getId()),
                [new DelayStamp(10_000)] // 10 seconds
            );

            return $this->render('compose/_send_toast.html.twig', [
                'message' => $message,
            ], new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']));
        }

        // validation failed — re-render compose window with errors
        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    #[Route('/undo/{id}', name: 'mail_undo', methods: ['POST'])]
    public function undo(Message $message): Response
    {
        // Security check — must own the message
        if ($message->getMailbox()->getAccount()->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $message->setCancelled(true);
        $this->em->flush();

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_undo_toast.html.twig', [
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
            throw new RuntimeException('No Drafts mailbox found for the selected account.');
        }

        $now = new DateTimeImmutable();

        $message
            ->setMailbox($draftsMailbox)
            ->setFromAddress($account->getEmail())
            ->setFromName($account->getName())
            ->addFlag(MessageFlag::DRAFT)
            ->setHasAttachments(false)
            ->setCreatedAt($now)
            ->setUpdatedAt($now)
        ;

        $this->em->persist($message);
        $this->em->flush();
    }
}
