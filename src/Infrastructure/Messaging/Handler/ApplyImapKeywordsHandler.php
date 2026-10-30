<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\ImapSession;
use App\Infrastructure\Messaging\Message\ApplyImapKeywordsMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\ImapConnector;
use App\Service\Imap\ImapPermanentFlags;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Put label keywords on, and take them off, the server copies of messages
 * (#221 step 2). Labels never move mail; this is a STORE and nothing else.
 *
 * Per folder: SELECT it (ImapPermanentFlags, which also records whether the
 * folder keeps keywords), and only where it does, `UID STORE +FLAGS.SILENT`
 * or `-FLAGS.SILENT` for that folder's UIDs. A folder that does not keep them
 * is skipped — the label stays in plMail on that mail, which is what the
 * indicator in the label dialog says.
 *
 * Through the connection, not webklex's Message::setFlag(): that one puts a
 * backslash in front of every flag, which makes a keyword a system flag the
 * server refuses.
 *
 * Afterwards the row's stored flags say what the server now has, which is
 * what lets the inbound pass tell this change from one another client made
 * (KeywordLabelFollower). The echo guard (Message::$flagsTouchedAt) is left
 * alone: it belongs to star and read, which may be in flight on the same row,
 * and the keyword path never set it. A server that cannot be reached is
 * retried, as for any flag.
 */
#[AsMessageHandler]
final readonly class ApplyImapKeywordsHandler
{
    public function __construct(
        private AccountRepository      $accounts,
        private MailboxRepository      $mailboxes,
        private MessageRepository      $messages,
        private ImapConnector          $connector,
        private ImapPermanentFlags     $permanentFlags,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ApplyImapKeywordsMessage $job): void
    {
        $account = $this->accounts->find($job->accountId);

        if (null === $account || false === $account->isPlainImap() || ([] === $job->add && [] === $job->remove)) {
            return;
        }

        /** @var array<int, array<int, int>> $byMailbox mailbox id => (message id => uid) */
        $byMailbox = [];

        foreach ($job->copies as $copy) {
            $byMailbox[$copy['mailbox']][$copy['message']] = $copy['uid'];
        }

        try {
            $session = $this->connector->session($account);
        } catch (ImapConnectionException $e) {
            $this->logger->warning('ApplyImapKeywordsHandler: server unreachable, will retry', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }

        try {
            foreach ($byMailbox as $mailboxId => $uids) {
                $mailbox = $this->mailboxes->find($mailboxId);

                if (null === $mailbox || $mailbox->account !== $account) {
                    continue;
                }

                $this->applyToFolder($session, $mailbox, $uids, $job);
            }
        } finally {
            try {
                $session->logout();
            } catch (Throwable) {
            }
        }

        $this->em->flush();
    }

    /**
     * @param array<int, int> $uids message id => uid
     */
    private function applyToFolder(ImapSession $session, Mailbox $mailbox, array $uids, ApplyImapKeywordsMessage $job): void
    {
        if (true !== $this->permanentFlags->probe($session, $mailbox)) {
            $this->logger->info('ApplyImapKeywordsHandler: folder does not keep keywords, labels stay in plMail', [
                'mailbox' => $mailbox->fullPath,
            ]);

            return;
        }

        $path = $mailbox->serverPath();
        $done = [];

        foreach ($uids as $messageId => $uid) {
            try {
                if ([] !== $job->add) {
                    $session->uidStore($path, (string) $uid, '+', $job->add);
                }

                if ([] !== $job->remove) {
                    $session->uidStore($path, (string) $uid, '-', $job->remove);
                }

                $done[$messageId] = $uid;
            } catch (ImapCommandException $e) {
                $this->logger->error('ApplyImapKeywordsHandler: STORE refused', [
                    'mailbox' => $mailbox->fullPath,
                    'uid'     => $uid,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->messages->findBy(['id' => array_keys($done)]) as $message) {
            $this->recordFlags($message, $mailbox, $done[(int) $message->id], $job);
        }
    }

    /**
     * The row's mirror of the server's flags, and the copy's own (#415), so the
     * inbound pass sees no difference to act on. Only the keywords; the echo
     * guard is not ours.
     */
    private function recordFlags(Message $message, Mailbox $mailbox, int $uid, ApplyImapKeywordsMessage $job): void
    {
        $flags  = array_values(array_diff($message->flags, $job->remove));
        $flags  = array_values(array_unique([...$flags, ...$job->add]));

        $message->flags = $flags;

        $copy = $message->locationAt($mailbox, $uid);

        if (null !== $copy && null !== $copy->serverFlags) {
            $copy->serverFlags = MessageFlag::canonicalList([...array_diff($copy->serverFlags, $job->remove), ...$job->add]);
        }
    }
}
