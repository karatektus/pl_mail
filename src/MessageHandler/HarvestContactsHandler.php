<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\HarvestContactsMessage;
use App\Repository\AccountRepository;
use App\Service\HarvestContactsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
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
