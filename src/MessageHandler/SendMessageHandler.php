<?php

namespace App\MessageHandler;
use App\Entity\Message;
use App\Message\SendMessageMessage;
use App\Repository\MessageRepository;
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
            $message->setCancelled(false);
            $this->em->flush();

            return;
        }

        $this->sendService->send($message);
    }
}
