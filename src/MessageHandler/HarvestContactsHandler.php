<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\HarvestContactsMessage;
use App\Repository\MailboxRepository;
use App\Service\HarvestContactsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class HarvestContactsHandler
{
    public function __construct(
        private MailboxRepository      $mailboxRepository,
        private HarvestContactsService $harvestService,
        private LoggerInterface        $logger,
    ) {}

    public function __invoke(HarvestContactsMessage $message): void
    {
        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if ($mailbox === null) {
            $this->logger->warning('HarvestContactsHandler: mailbox not found', [
                'mailboxId' => $message->mailboxId,
            ]);

            return;
        }

        $this->harvestService->harvestForMailbox($mailbox);
    }
}
