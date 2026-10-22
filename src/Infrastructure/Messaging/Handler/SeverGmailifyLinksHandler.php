<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\SeverGmailifyLinksMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\Gmail\GmailifyLinks;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Detaches the IMAP copies a Gmail mailbox carries once its Gmailify switch is
 * off. See SeverGmailifyLinksMessage.
 *
 * The switch is read again here, not trusted from the dispatch: a job waits on
 * the queue, and somebody who turned Gmailify off and straight back on meant
 * the second. Severing then would take the links off a mailbox that has asked
 * to keep them.
 *
 * Safe to run twice: a detached copy has no carrier, so it is not among the
 * rows a second run finds, and a run cut short picks up where it stopped.
 */
#[AsMessageHandler]
final readonly class SeverGmailifyLinksHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private GmailifyLinks     $links,
        private LoggerInterface   $logger,
    ) {
    }

    public function __invoke(SeverGmailifyLinksMessage $message): void
    {
        $account = $this->accounts->find($message->accountId);

        if (null === $account || true === $account->gmailifyEnabled) {
            return;
        }

        $detached = $this->links->severCarriedBy($message->accountId);

        $this->logger->info('Gmailify switched off: the other accounts\' copies were detached', [
            'accountId' => $message->accountId,
            'detached'  => $detached,
        ]);
    }
}
