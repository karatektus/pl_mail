<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\PurgedCopy;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\ImapSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The server copies of mail deleted forever here, until the server has
 * deleted them too (#427). See PurgedCopy.
 *
 * Written by the purge, cleared by the purge job when the server confirms,
 * and read by the sync, which passes over these addresses — the mail stays
 * deleted while the server catches up — and asks the server again about any
 * that the job did not get through.
 */
final readonly class PurgedCopies
{
    /**
     * How long the purge job has before the sync asks the server itself. Long
     * enough that a sync never races a job that is merely queued; short enough
     * that a refused EXPUNGE does not leave the mail on the server for long.
     */
    private const string RETRY_AFTER = '-10 minutes';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * Leave a record of every copy the server keeps of this message, and say
     * which they are, for the job that deletes them there.
     *
     * Every copy and not only the one the list was showing: deleting an email
     * forever means it is nowhere any more (#415). Not flushed; the purge does.
     *
     * @return array<string, list<int>> folder path => UIDs
     */
    public function record(Message $message): array
    {
        $byFolder = [];

        foreach ($message->locations as $location) {
            if (null === $location->imapUid) {
                continue;
            }

            $this->em->persist(new PurgedCopy(
                $location->mailbox,
                $location->imapUid,
                $message->messageId,
                $message->rfc822Size,
                $message->serverEmailId,
            ));
            $byFolder[(string) $location->mailbox->fullPath][] = $location->imapUid;
        }

        return $byFolder;
    }

    /**
     * The server has deleted these: forget them.
     *
     * @param list<int> $uids
     */
    public function confirm(Account $account, string $path, array $uids): void
    {
        if ([] === $uids) {
            return;
        }

        $this->em->createQueryBuilder()
            ->delete(PurgedCopy::class, 'p')
            ->where('p.imapUid IN (:uids)')
            ->andWhere('p.mailbox IN (SELECT mb.id FROM ' . Mailbox::class . ' mb WHERE mb.account = :account AND mb.fullPath = :path)')
            ->setParameter('uids', $uids)
            ->setParameter('account', $account)
            ->setParameter('path', $path)
            ->getQuery()
            ->execute();
    }

    /**
     * The addresses in this folder the sync must pass over.
     *
     * @return list<int>
     */
    public function uidsIn(Mailbox $mailbox): array
    {
        return array_map(intval(...), $this->em->createQueryBuilder()
            ->select('p.imapUid')
            ->from(PurgedCopy::class, 'p')
            ->where('p.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Ask the server again about this folder's copies the purge job has not
     * got through, on the sync's own session.
     *
     * The job only logged a refused EXPUNGE, and the mail stayed on the
     * server flagged \Deleted (#427). Here, a copy the server still has is
     * deleted again, and a record whose UID the server no longer has goes —
     * the server has said what the job was waiting to hear. Only records older
     * than RETRY_AFTER: a younger one belongs to a job still in the queue.
     */
    public function settle(Mailbox $mailbox, ImapSession $session, ?DateTimeImmutable $now = null): void
    {
        /** @var list<PurgedCopy> $stale */
        $stale = $this->em->createQueryBuilder()
            ->select('p')
            ->from(PurgedCopy::class, 'p')
            ->where('p.mailbox = :mailbox')
            ->andWhere('p.purgedAt < :before')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('before', ($now ?? new DateTimeImmutable())->modify(self::RETRY_AFTER))
            ->getQuery()
            ->getResult();

        if ([] === $stale) {
            return;
        }

        $path = $mailbox->serverPath();

        try {
            // A folder rebuilt since: its UIDs are other messages now. The
            // records go, and nothing is deleted on their word.
            $uidValidity = $session->select($path)->uidValidity;

            foreach ($stale as $index => $copy) {
                if (null !== $copy->uidValidity && null !== $uidValidity && $copy->uidValidity !== $uidValidity) {
                    $this->em->remove($copy);
                    unset($stale[$index]);
                }
            }

            if ([] === $stale) {
                $this->em->flush();

                return;
            }

            $uids    = array_values(array_map(static fn (PurgedCopy $copy): int => $copy->imapUid, $stale));
            $set     = ImapSession::uidSet($uids);
            $present = $session->uidSearch($path, 'UID ' . $set);

            if ([] !== $present) {
                $session->uidStore($path, ImapSession::uidSet($present), '+', ['\\Deleted']);

                if (true === $session->connection->supports('UIDPLUS')) {
                    $session->expunge($path, ImapSession::uidSet($present));
                }

                $present = $session->uidSearch($path, 'UID ' . $set);
            }
        } catch (ImapCommandException $refused) {
            $this->logger->warning('Could not delete purged mail on the server; asking again next sync', [
                'mailbox' => $mailbox->fullPath,
                'count'   => count($stale),
                'error'   => $refused->getMessage(),
            ]);

            return;
        }

        foreach ($stale as $copy) {
            if (false === in_array($copy->imapUid, $present, true)) {
                $this->em->remove($copy);
            }
        }

        $this->em->flush();
    }
}
