<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Client;

/**
 * "Is this UID still in that folder?" — asked of the server, once per question.
 *
 * The evidence that separates a move from a copy. Two folders holding the same
 * Message-ID look identical from the destination; what tells them apart is
 * whether the source copy is still where the database thinks it is. See
 * SentCopyReconciler for why that question is the one worth asking.
 *
 * Three answers, and the third is the important one. True and false are the
 * server's. Null means the server did not answer — an unreachable host, a
 * folder that has been renamed, a fetch that threw — and null must never be
 * read as "gone", because the caller's response to "gone" is to delete rows.
 * Anything that cannot be established is left alone.
 *
 * ## Its own connection, deliberately
 *
 * The syncer asks this from inside a chunked() iteration, and chunked() runs
 * one search over the folder it is on and then pages through the result. Every
 * probe SELECTs a different folder on the connection it borrows, in the middle
 * of that paging. Rather than reason about how much of the library's state
 * survives having the selected mailbox changed underneath it, this opens a
 * second connection and leaves the syncer's alone.
 *
 * Lazily, and that is what makes the cost acceptable: a healthy account never
 * has two rows claiming one Message-ID, so it never asks a question, so it
 * never opens the connection. The accounts that do pay for one connection per
 * sync, which is what repairing them is worth.
 */
final class ImapUidPresence
{
    /** @var array<string, bool|null> memoised answers, "mailboxId:uid" keyed */
    private array $answers = [];

    private ?Client $client = null;

    private bool $unavailable = false;

    public function __construct(
        private readonly Account               $account,
        private readonly ImapConnectionFactory $connections,
        private readonly LoggerInterface       $logger,
    ) {
    }

    /**
     * Invokable so it can be handed straight over as the probe callable.
     */
    public function __invoke(Mailbox $mailbox, int $uid): ?bool
    {
        $key = $mailbox->id . ':' . $uid;

        if (true === array_key_exists($key, $this->answers)) {
            return $this->answers[$key];
        }

        return $this->answers[$key] = $this->ask($mailbox, $uid);
    }

    public function close(): void
    {
        try {
            $this->client?->disconnect();
        } catch (Throwable) {
            // Closing a connection that is already gone is not a failure.
        }

        $this->client = null;
    }

    private function ask(Mailbox $mailbox, int $uid): ?bool
    {
        $client = $this->client();

        if (null === $client) {
            return null;
        }

        try {
            $folder = $client->getFolder((string) $mailbox->name)
                ?? $client->getFolder((string) $mailbox->fullPath);

            if (null === $folder) {
                // The folder itself is gone. Every row claiming an address in
                // it is stale, but "the folder was deleted" is a different
                // event from "the message moved", with different consequences,
                // and this is not the place to conflate them.
                return null;
            }

            $found = $folder->messages()
                ->whereUid($uid)
                ->setFetchBody(false)
                ->get()
                ->first();

            return null !== $found;
        } catch (Throwable $e) {
            $this->logger->info('Could not establish whether a UID is still on the server', [
                'mailbox' => $mailbox->fullPath,
                'uid'     => $uid,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function client(): ?Client
    {
        if (null !== $this->client) {
            return $this->client;
        }

        if (true === $this->unavailable) {
            return null;
        }

        try {
            return $this->client = $this->connections->connect($this->account);
        } catch (Throwable $e) {
            // Once, not once per question: a server that refused the second
            // connection will refuse the next fifty just as slowly.
            $this->unavailable = true;

            $this->logger->info('No second IMAP connection for move reconciliation', [
                'account' => $this->account->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
