<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Full IMAP account sync: folder discovery first (so new folders created by
 * other clients appear and get their label chains), then message sync per
 * sync-enabled mailbox. app:mail:sync is therefore the single entry point
 * for both structure and content.
 */
final readonly class ImapAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private MailboxRepository     $mailboxRepository,
        private MailboxSyncer         $mailboxSyncer,
        private MessageSyncer         $messageSyncer,
        private ImapConnectionFactory $imapConnectionFactory,
        private LoggerInterface       $logger,
        private EntityManagerInterface $em,
        private ManagerRegistry       $registry,
    ) {}

    public function supports(Account $account): bool
    {
        if (true === $account->isGmail()) {
            return false;
        }

        if (true === $account->isMicrosoft()) {
            return false;
        }

        return true;
    }
    public function sync(Account $account): array
    {
        try {
            $structure = $this->mailboxSyncer->syncForAccount($account);

            $this->logger->info('ImapAccountSyncer: mailbox structure synced', [
                'accountId' => $account->id,
                'created'   => $structure['created'],
                'updated'   => $structure['updated'],
                'deleted'   => $structure['deleted'],
                'renamed'   => $structure['renamed'],
                'missing'   => $structure['missing'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ImapAccountSyncer: mailbox structure sync failed', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        // A folder the server has stopped listing cannot be selected, so
        // syncing it would only log a failure per poll until MailboxSyncer
        // either sees it again or removes it.
        $mailboxes = $this->mailboxRepository->findBy([
            'account'       => $account,
            'isSyncEnabled' => true,
            'missingSince'  => null,
        ]);

        if (count($mailboxes) === 0) {
            $this->logger->info('ImapAccountSyncer: no sync-enabled mailboxes', [
                'accountId' => $account->id,
            ]);

            return [];
        }

        $mailboxIds       = array_map(static fn (Mailbox $mailbox): int => (int) $mailbox->id, $mailboxes);
        $client           = $this->imapConnectionFactory->connect($account);
        $syncedMailboxIds = [];

        try {
            foreach ($mailboxIds as $mailboxId) {
                // Found again for each folder rather than taken from the list
                // above. MessageSyncer clears the entity manager between
                // batches, so once one folder had new mail every later entry
                // in that list was detached, and what the sweep wrote onto it
                // (sweptAt, the folder's UIDVALIDITY) was flushed into
                // nothing. A folder that never records its UIDVALIDITY cannot
                // be recognised as rebuilt on the server.
                $mailbox = $this->mailboxRepository->find($mailboxId);

                if (null === $mailbox) {
                    continue;
                }

                try {
                    $this->messageSyncer->syncMailbox($mailbox, $client);
                    $syncedMailboxIds[] = $mailboxId;
                } catch (\Throwable $e) {
                    $this->logger->error('ImapAccountSyncer: mailbox sync failed', [
                        'mailboxId' => $mailboxId,
                        'error'     => $e->getMessage(),
                        'exception' => $e,
                    ]);

                    // A failure out of a flush closes the manager, and the next
                    // folder would die on its first persist, with every one of
                    // its messages blamed for it. Reopened, one folder's failure
                    // stays that folder's.
                    if (false === $this->em->isOpen()) {
                        $this->registry->resetManager();
                    }
                }
            }
        } finally {
            $client->disconnect();
        }

        return $syncedMailboxIds;
    }
}
