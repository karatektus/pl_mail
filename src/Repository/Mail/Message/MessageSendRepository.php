<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\SendFailure;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The send claim: who may send a message, and what happened when it went.
 *
 * Every write here is one guarded UPDATE, so a cancel and a send cannot both win.
 */
class MessageSendRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Take ownership of a message for sending, or report that it is not ours.
     *
     * The whole point is that this is ONE statement. SendMessageHandler used to
     * read `cancelled` and then send, which is two, and an undo that committed
     * between them vanished without trace — the mail went out and the user had
     * already been told it was cancelled. Here the guard and the claim happen
     * in the same UPDATE, so the answer to "may I send this?" is the same event
     * as "nobody else may cancel it now", and Postgres is what decides.
     *
     * The three conditions are the three ways a send can be illegitimate: it
     * was cancelled, it already left, or another worker holds it. Returning
     * false covers all three, and the caller does not need to know which —
     * every one of them means "do not send".
     *
     * Deliberately raw DBAL and deliberately not going through the ORM: an
     * entity write would be a read-modify-write through the identity map, which
     * is exactly the shape that lost the race in the first place.
     *
     * With $pinsSendAt the claim also requires submission_send_at to still be
     * $sendAt — the release time the envelope was dispatched for. A fourth way
     * a send can be illegitimate: it was rescheduled, and this envelope is the
     * old schedule's. See SendMessageMessage. IS NOT DISTINCT FROM because
     * NULL is a value here (a composer send has no submission time) and `=`
     * would never match it.
     */
    public function claimForSend(int $messageId, bool $pinsSendAt = false, ?DateTimeImmutable $sendAt = null): bool
    {
        $parameters = ['id' => $messageId];
        $types      = [];
        $schedule   = '';

        if (true === $pinsSendAt) {
            $schedule             = 'AND submission_send_at IS NOT DISTINCT FROM :sendAt';
            $parameters['sendAt'] = $sendAt;
            $types['sendAt']      = Types::DATETIME_IMMUTABLE;
        }

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            // The database's own clock, not PHP's: the claim is decided here,
            // so the timestamp that records it should be too.
            // A warning left by an earlier send is lifted by this one: the
            // draft is being sent again, and if this attempt fails too it is
            // marked afresh (#125).
            'UPDATE message
                SET send_claimed_at = LOCALTIMESTAMP,
                    send_failed_at = NULL,
                    send_failure = NULL
              WHERE id = :id
                AND cancelled = false
                AND sent_at IS NULL
                ' . $schedule . '
                AND (
                    send_claimed_at IS NULL
                    -- A claim is "a handler has this right now", and a handler
                    -- that was killed between claiming and releasing cannot say
                    -- so. Without an expiry that draft could never be sent
                    -- again by anything: the retry, a resubmission and the JMAP
                    -- path would all be refused by bookkeeping belonging to a
                    -- process that no longer exists. Far longer than any send
                    -- takes and far shorter than a person waits before
                    -- complaining.
                    OR send_claimed_at < LOCALTIMESTAMP - INTERVAL \'15 minutes\'
                )',
            $parameters,
            $types,
        );

        return $affected > 0;
    }

    /**
     * Call a send off, and say whether the call arrived in time.
     *
     * The mirror image of claimForSend(), and the reason a cancel can now be
     * reported honestly. `send_claimed_at IS NULL` is what makes it truthful:
     * once the handler holds the message the cancel has lost, even though
     * sent_at may still be null for as long as the SMTP conversation takes.
     * Checking sent_at alone would have answered "cancelled" for the whole
     * duration of the send that was in flight.
     *
     * submission_send_at is cleared in the same statement because a hold that
     * has been called off must stop being reported as `pending` by
     * EmailSubmission/get — see ComposeController::callOffSend().
     *
     * $cancelledAt is the JMAP shape of the same act, and differs on purpose:
     * EmailSubmission/set keeps submission_send_at and records the cancel in
     * submission_cancelled_at instead, because EmailSubmission/get reports the
     * submission as `canceled` from those two and would answer notFound with
     * the release time gone. The guard is the same either way — that is the
     * part that must not differ between the two surfaces.
     */
    public function cancelSend(int $messageId, ?DateTimeImmutable $cancelledAt = null): bool
    {
        if (null !== $cancelledAt) {
            $affected = $this->getEntityManager()->getConnection()->executeStatement(
                'UPDATE message
                    SET cancelled = true,
                        submission_cancelled_at = :cancelledAt
                  WHERE id = :id
                    AND send_claimed_at IS NULL
                    AND sent_at IS NULL',
                ['id' => $messageId, 'cancelledAt' => $cancelledAt],
                ['cancelledAt' => Types::DATETIME_IMMUTABLE],
            );

            return $affected > 0;
        }

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message
                SET cancelled = true,
                    submission_send_at = NULL
              WHERE id = :id
                AND send_claimed_at IS NULL
                AND sent_at IS NULL',
            ['id' => $messageId],
        );

        return $affected > 0;
    }

    /**
     * Give a claimed message back, because the send did not happen.
     *
     * Without this a failed send would wedge the message forever: the claim
     * would stand, and the messenger retry — plus any later resubmission from
     * the composer or from EmailSubmission/set — would be refused by
     * claimForSend() as "somebody else holds it". A claim describes a handler
     * that is working on the message right now, so a handler that has stopped
     * working on it has to put it down.
     */
    public function releaseSendClaim(int $messageId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message SET send_claimed_at = NULL WHERE id = :id AND sent_at IS NULL',
            ['id' => $messageId],
        );
    }

    /**
     * Start the clock on a send that is being retried, if it is not running
     * already, and say how long it has been running in seconds (#125).
     *
     * One statement for both, so two attempts racing each other cannot each
     * start their own clock. The database's clock, as for the claim.
     *
     * Raw DBAL because that one statement is the point and DQL cannot write
     * it: it has no `UPDATE … RETURNING`, and no `EXTRACT(EPOCH FROM …)` to
     * turn the interval into seconds. Split into an UPDATE and a SELECT it
     * would be two statements again, and the race back.
     */
    public function secondsRetryingSend(int $messageId): int
    {
        $elapsed = $this->getEntityManager()->getConnection()->fetchOne(
            'UPDATE message
                SET send_retrying_since = COALESCE(send_retrying_since, LOCALTIMESTAMP)
              WHERE id = :id
          RETURNING EXTRACT(EPOCH FROM LOCALTIMESTAMP - send_retrying_since)',
            ['id' => $messageId],
        );

        return false === $elapsed ? 0 : (int) $elapsed;
    }

    /**
     * Note that an attempt lost its answer, so the next one looks in Sent
     * before sending. See SendOutcomeUnknownException.
     */
    public function markSendUnconfirmed(int $messageId): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.sendUnconfirmedAt', 'CURRENT_TIMESTAMP()')
            ->where('m.id = :id')
            ->andWhere('m.sentAt IS NULL')
            ->setParameter('id', $messageId)
            ->getQuery()
            ->execute();
    }

    /**
     * Mark a draft whose send finally failed, and stop its retry clock (#125).
     *
     * A bulk UPDATE rather than a change to the entity: it is written on the
     * way out of a failed send, after the entity manager has been cleared of
     * whatever the attempt left half-done, and a bulk update goes to the
     * database without reading anything back into it. Not on a message that
     * went out after all — sent_at is the one fact that wins.
     */
    public function markSendFailed(int $messageId, SendFailure $failure): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.sendFailedAt', 'CURRENT_TIMESTAMP()')
            ->set('m.sendFailure', ':failure')
            ->set('m.sendRetryingSince', 'NULL')
            ->set('m.sendUnconfirmedAt', 'NULL')
            ->where('m.id = :id')
            ->andWhere('m.sentAt IS NULL')
            ->setParameter('id', $messageId)
            ->setParameter('failure', $failure->value)
            ->getQuery()
            ->execute();
    }

    /**
     * How many of a user's drafts carry a failed-send warning — the sidebar's
     * mark on Drafts. Drafts only, by label as the Drafts list selects them, so
     * a marked draft that was thrown away stops counting with it.
     */
    public function countUnsentDraftsForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.id)')
            ->innerJoin('m.account', 'a')
            ->innerJoin('m.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('m.sendFailedAt IS NOT NULL')
            ->andWhere('m.sentAt IS NULL')
            ->andWhere('l.role = :drafts')
            ->setParameter('user', $user)
            ->setParameter('drafts', LabelRole::Drafts)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
