<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\HarvestContactsMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\HarvestContactsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
/**
 * Nothing dispatches this any more — HarvestContactsStep learns addresses from
 * each ingested batch, and `app:backfill contacts` does the full sweep when one
 * is actually wanted.
 *
 * Kept for one release rather than deleted with the dispatchers. Any message
 * still sitting in the queue at upgrade time has to have a handler to land on;
 * removing both at once turns a routine deploy into a pile of unhandleable
 * envelopes in the failed transport. Delete after a release has drained.
 */
final readonly class HarvestContactsHandler
{
    public function __construct(
        private AccountRepository      $accountRepository,
        private HarvestContactsService $harvestService,
        private LoggerInterface        $logger,
    ) {}

    public function __invoke(HarvestContactsMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            $this->logger->warning("HarvestContactsHandler: account not found", [
                "accountId" => $message->accountId,
            ]);

            return;
        }

        $this->harvestService->harvestForAccount($account);
    }
}
