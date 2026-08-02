<?php

namespace App\Infrastructure\Messaging\Handler;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageSendService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendMessageHandler
{
    public function __construct(
        private MessageRepository  $messageRepository,
        private MessageSendService $sendService,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(SendMessageMessage $msg): void
    {
        /** @var Message $message */
        $message = $this->messageRepository->find($msg->messageId);

        if (null === $message) {
            return;
        }

        if (true === $message->isCancelled()) {
            // Not recorded, for the same reason ComposeController::undo()
            // does not record setting it: `cancelled` is private traffic
            // between that button and this handler, EmailMapper publishes
            // nothing derived from it, and the message is still the same
            // unsent draft a client already holds.
            $message->setCancelled(false);
            $this->em->flush();

            return;
        }

        if (null !== $message->getSentAt()) {
            return;
        }

        $this->sendService->send($message);
    }
}
