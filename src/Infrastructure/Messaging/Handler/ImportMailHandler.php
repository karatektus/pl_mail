<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\ImportMailMessage;
use App\Service\Mail\MailImporter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One page of an account's history. Everything it does is MailImporter's.
 */
#[AsMessageHandler]
final readonly class ImportMailHandler
{
    public function __construct(
        private MailImporter $importer,
    ) {}

    public function __invoke(ImportMailMessage $message): void
    {
        $this->importer->runPage($message->accountId);
    }
}
