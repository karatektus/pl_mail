<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\FoldCopiesMessage;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\ImapCopyMerger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FoldCopiesHandler
{
    public function __construct(
        private MailboxRepository $mailboxes,
        private ImapCopyMerger    $merger,
    ) {
    }

    public function __invoke(FoldCopiesMessage $message): void
    {
        $mailbox = $this->mailboxes->find($message->mailboxId);

        if (null === $mailbox) {
            return;
        }

        $this->merger->foldKeptIn($mailbox);
    }
}
