<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\HarvestContactsMessage;
use App\Repository\ContactRepository;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class HarvestContactsHandler
{
    public function __construct(
        private MailboxRepository  $mailboxRepository,
        private MessageRepository  $messageRepository,
        private ContactRepository  $contactRepository,
        private LoggerInterface    $logger,
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

        $user = $mailbox->getAccount()->getUsr();

        // Load all messages for this mailbox; we only need address columns
        // so memory usage is modest (no bodies loaded).
        $messages = $this->messageRepository->findByMailboxOrderedByDate($mailbox);

        $batch = [];

        foreach ($messages as $msg) {
            // From
            if ($msg->getFromAddress() !== null && $msg->getFromAddress() !== '') {
                $batch[] = [
                    'email' => $msg->getFromAddress(),
                    'name'  => $msg->getFromName(),
                ];
            }

            // To / Cc / Bcc
            foreach ([
                         $msg->getToAddresses(),
                         $msg->getCcAddresses(),
                         $msg->getBccAddresses(),
                     ] as $group) {
                if ($group === null) {
                    continue;
                }

                foreach ($group as $addr) {
                    if (isset($addr['address']) && $addr['address'] !== '') {
                        $batch[] = [
                            'email' => $addr['address'],
                            'name'  => $addr['name'] ?? null,
                        ];
                    }
                }
            }

            // Flush in chunks of 200 to avoid huge IN-memory arrays.
            if (count($batch) >= 200) {
                $this->contactRepository->upsertBatch($user, $batch);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            $this->contactRepository->upsertBatch($user, $batch);
        }

        $this->logger->info('HarvestContactsHandler: done', [
            'mailboxId' => $message->mailboxId,
            'messages'  => count($messages),
        ]);
    }
}
