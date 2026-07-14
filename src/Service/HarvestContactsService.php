<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Mailbox;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;

final readonly class HarvestContactsService
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageRepository $messageRepository,
        private ContactRepository $contactRepository,
        private LoggerInterface   $logger,
    ) {}

    /**
     * Harvests all from/to/cc/bcc addresses from the given mailbox's messages
     * and upserts them into the contact table for the owning user.
     *
     * Returns the total number of address occurrences processed.
     */
    public function harvestForMailbox(Mailbox $mailbox): int
    {
        $user     = $mailbox->getAccount()->getUsr();
        $messages = $this->messageRepository->findByMailboxOrderedByDate($mailbox);
        $batch    = [];
        $total    = 0;

        foreach ($messages as $msg) {
            if ($msg->getFromAddress() !== null && $msg->getFromAddress() !== '') {
                $batch[] = [
                    'email' => $msg->getFromAddress(),
                    'name'  => $msg->getFromName(),
                ];
            }

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

            if (count($batch) >= self::BATCH_SIZE) {
                $this->contactRepository->upsertBatch($user, $batch);
                $total += count($batch);
                $batch  = [];
            }
        }

        if (count($batch) > 0) {
            $this->contactRepository->upsertBatch($user, $batch);
            $total += count($batch);
        }

        $this->logger->info('HarvestContactsService: mailbox done', [
            'mailboxId' => $mailbox->getId(),
            'messages'  => count($messages),
            'addresses' => $total,
        ]);

        return $total;
    }
}
