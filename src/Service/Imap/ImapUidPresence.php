<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Mail\Mailbox;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\ImapSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "Is this UID still in that folder?" — asked of the server, once per question.
 *
 * The evidence that separates a move from a copy. Two folders holding the same
 * Message-ID look identical from the destination; what tells them apart is
 * whether the source copy is still where the database thinks it is. See
 * SentCopyReconciler for why that question is the one worth asking.
 *
 * Three answers, and the third is the important one. True and false are the
 * server's. Null means the server did not answer — a folder that has been
 * renamed, a connection that dropped — and null must never be read as
 * "gone", because the caller's response to "gone" is to delete rows.
 * Anything that cannot be established is left alone.
 *
 * On the sync's own session. It used to open a second connection, because
 * the library's paging held state about the selected folder that a probe in
 * between would have pulled out from under it. ImapSession selects whatever
 * folder a command is for, so a probe between two pages costs the next page
 * one SELECT and nothing else.
 */
final class ImapUidPresence
{
    /** @var array<string, bool|null> memoised answers, "mailboxId:uid" keyed */
    private array $answers = [];

    /** Set once the connection has failed: nothing more will be answered on it. */
    private bool $unavailable = false;

    public function __construct(
        private readonly ImapSession     $session,
        private readonly LoggerInterface $logger,
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

    private function ask(Mailbox $mailbox, int $uid): ?bool
    {
        if (true === $this->unavailable) {
            return null;
        }

        try {
            return self::holds($this->session, $mailbox->serverPath(), $uid);
        } catch (ImapCommandException $refused) {
            // Most likely the folder itself is gone. Every row claiming an
            // address in it is stale, but "the folder was deleted" is a
            // different event from "the message moved", with different
            // consequences, and this is not the place to conflate them.
            $this->logger->info('Could not establish whether a UID is still on the server', [
                'mailbox' => $mailbox->fullPath,
                'uid'     => $uid,
                'error'   => $refused->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            // Once, not once per question: a connection that has failed will
            // fail the next fifty just as slowly.
            if ($e instanceof ImapConnectionException) {
                $this->unavailable = true;
            }

            $this->logger->log(ThrowableSeverity::level($e), 'Could not establish whether a UID is still on the server', [
                'mailbox'   => $mailbox->fullPath,
                'uid'       => $uid,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Whether the folder lists the UID, by UID SEARCH alone.
     *
     * Not a fetch of the message: the question is whether the UID exists, and
     * building a message to answer it put the header parser in the way (#357).
     * SEARCH returns numbers and parses nothing.
     */
    public static function holds(ImapSession $session, string $path, int $uid): bool
    {
        return true === in_array($uid, $session->uidSearch($path, sprintf('UID %d', $uid)), true);
    }
}
