<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * What the sender pictures (#639) need from the message table: recording
 * which messages passed the sender check, and who a user has heard from
 * lately.
 */
class MessageSenderPictureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Marks these messages as having passed the sender check. One statement
     * for a whole ingest batch; only the messages that passed are named, so a
     * batch that is all newsletters from unchecked servers writes nothing.
     *
     * @param list<int> $messageIds
     */
    public function markSenderVerified(array $messageIds): void
    {
        if ([] === $messageIds) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message SET sender_verified = true WHERE id IN (:ids)',
            ['ids' => $messageIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * The addresses that have written to $user since $since, most recent
     * first, lower-cased — what a backfill looks pictures up for when the
     * feature is switched on.
     *
     * Most recent first so that a limit cuts off the people the user has not
     * heard from in a while rather than an arbitrary half of the alphabet.
     * Raw DBAL for the GROUP BY over an expression with an ORDER BY on an
     * aggregate, which DQL handles only grudgingly.
     *
     * @return list<string>
     */
    public function recentSenders(User $user, \DateTimeImmutable $since, int $limit): array
    {
        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT LOWER(m.from_address) AS address
                  FROM message m
                  JOIN account a ON a.id = m.account_id
                 WHERE a.usr_id = :userId
                   AND m.from_address IS NOT NULL
                   AND m.vanished_at IS NULL
                   AND m.received_at >= :since
                 GROUP BY LOWER(m.from_address)
                 ORDER BY MAX(m.received_at) DESC
                 LIMIT :limit
                SQL,
            [
                'userId' => $user->id,
                'since'  => $since->format('Y-m-d H:i:s'),
                'limit'  => $limit,
            ],
        );
    }
}
