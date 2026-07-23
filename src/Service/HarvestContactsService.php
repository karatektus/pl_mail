<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Message;
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
     * Harvest every message that belongs to the account — via its mailbox
     * (IMAP) or its thread (Gmail-API messages have no mailbox row).
     */
    public function harvestForAccount(Account $account): int
    {
        $user  = $account->getUsr();
        $total = $this->upsertFromMessages(
            $user,
            $this->messageRepository->iterateForAccount($account),
            $account->getEmail()
        );

        $this->logger->info('HarvestContactsService: account done', [
            'accountId' => $account->getId(),
            'addresses' => $total,
        ]);

        return $total;
    }

    /**
     * @param list<Message> $messages
     */
    public function harvestMessages(User $user, array $messages, string $ownAddress): int
    {
        return $this->upsertFromMessages($user, $messages, $ownAddress);
    }

    /**
     * @param iterable<Message> $messages
     */
    private function upsertFromMessages(User $user, iterable $messages, string $ownAddress): int
    {
        $batch = [];
        $total = 0;

        foreach ($messages as $msg) {
            $isOutbound = '' !== $ownAddress
                && mb_strtolower(trim((string) $msg->getFromAddress())) === $ownAddress;

            if ($msg->getFromAddress() !== null && $msg->getFromAddress() !== '') {
                $batch[] = ['email' => $msg->getFromAddress(), 'name' => $msg->getFromName(), 'correspondent' => false];
            }

            foreach ([$msg->getToAddresses(), $msg->getCcAddresses(), $msg->getBccAddresses()] as $group) {
                if (null === $group) {
                    continue;
                }

                foreach ($group as $addr) {
                    if (true === isset($addr['address']) && '' !== $addr['address']) {
                        $batch[] = ['email' => $addr['address'], 'name' => $addr['name'] ?? null, 'correspondent' => $isOutbound];
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

        return $total;
    }
}
