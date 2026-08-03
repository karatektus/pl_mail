<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageRepository;
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
        $user  = $account->usr;
        $total = $this->upsertFromRows(
            $user,
            $this->messageRepository->iterateAddressesForAccount($account),
            (string) $account->email
        );

        $this->logger->info('HarvestContactsService: account done', [
            'accountId' => $account->id,
            'addresses' => $total,
        ]);

        return $total;
    }

    /**
     * @param list<Message> $messages
     */
    public function harvestMessages(User $user, array $messages, string $ownAddress): int
    {
        $rows = [];

        foreach ($messages as $message) {
            $rows[] = [
                'fromAddress'  => $message->fromAddress,
                'fromName'     => $message->fromName,
                'toAddresses'  => $message->toAddresses,
                'ccAddresses'  => $message->ccAddresses,
                'bccAddresses' => $message->bccAddresses,
            ];
        }

        return $this->upsertFromRows($user, $rows, $ownAddress);
    }

    /**
     * The five address fields are all this needs, so it takes them directly.
     *
     * Both callers reduce to the same rows: the sweep selects them out of the
     * database, and the per-batch path reads them off messages it already
     * holds. One loop rather than one that knew about entities and one that did
     * not, which is what let the sweep quietly fetch whole messages for years.
     *
     * @param iterable<array{fromAddress: ?string, fromName: ?string, toAddresses: ?array<mixed>, ccAddresses: ?array<mixed>, bccAddresses: ?array<mixed>}> $rows
     */
    private function upsertFromRows(User $user, iterable $rows, string $ownAddress): int
    {
        $batch = [];
        $total = 0;

        foreach ($rows as $row) {
            $from = $row['fromAddress'] ?? null;

            $isOutbound = '' !== $ownAddress
                && mb_strtolower(trim((string) $from)) === $ownAddress;

            if (null !== $from && '' !== $from) {
                $batch[] = ['email' => $from, 'name' => $row['fromName'] ?? null, 'correspondent' => false];
            }

            foreach ([$row['toAddresses'] ?? null, $row['ccAddresses'] ?? null, $row['bccAddresses'] ?? null] as $group) {
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
