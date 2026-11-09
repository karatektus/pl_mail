<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Mail exchanged with a contact: what a contact's page lists, and where a
 * contact's signature is looked for.
 */
class MessageContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * A user's mail from or to any of these addresses, newest first, as the
     * columns the contact page lists. Raw DBAL for the jsonb recipient lists,
     * which DQL cannot look into.
     *
     * @param non-empty-list<string> $emails lower-cased
     *
     * @return list<array<string, mixed>>
     */
    public function findExchangedWith(User $user, array $emails, int $limit): array
    {
        // NOTE: four separate :emailsN placeholders — DBAL does not reliably
        // allow the same named parameter twice in one statement.
        $sql = 'SELECT m.id, m.subject, m.from_address, m.from_name, m.received_at,'
            // One folder name per message, whichever location came first: the
            // list shows one, and a join would repeat the message per copy.
            . ' m.starred_at, m.has_attachments,'
            . ' (SELECT mb.name FROM message_location l JOIN mailbox mb ON mb.id = l.mailbox_id'
            . '   WHERE l.message_id = m.id ORDER BY l.id LIMIT 1) AS mailbox_name'
            . ' FROM message m'
            . ' JOIN account a ON a.id = m.account_id'
            . ' WHERE a.usr_id = :userId'
            . ' AND m.vanished_at IS NULL'
            . ' AND ('
            . ' LOWER(m.from_address) IN (:emails1)'
            . ' OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(m.to_addresses, \'[]\')::jsonb) e'
            . '   WHERE LOWER(e->>\'address\') IN (:emails2))'
            . ' OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(m.cc_addresses, \'[]\')::jsonb) e'
            . '   WHERE LOWER(e->>\'address\') IN (:emails3))'
            . ' OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(m.bcc_addresses, \'[]\')::jsonb) e'
            . '   WHERE LOWER(e->>\'address\') IN (:emails4))'
            . ' )'
            . ' ORDER BY m.received_at DESC NULLS LAST'
            . ' LIMIT :limit';

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            [
                'userId' => $user->id,
                'emails1' => $emails,
                'emails2' => $emails,
                'emails3' => $emails,
                'emails4' => $emails,
                'limit' => $limit,
            ],
            [
                'userId' => ParameterType::INTEGER,
                'emails1' => ArrayParameterType::STRING,
                'emails2' => ArrayParameterType::STRING,
                'emails3' => ArrayParameterType::STRING,
                'emails4' => ArrayParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * The newest few mails a user received from any of these addresses, for
     * finding the sender's signature.
     *
     * @param non-empty-list<string> $addresses lower-cased
     *
     * @return list<Message>
     */
    public function findLatestFrom(User $user, array $addresses, int $limit): array
    {
        /** @var list<Message> */
        return $this->createQueryBuilder('m')
            ->join('m.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('LOWER(m.fromAddress) IN (:addresses)')
            ->andWhere('m.vanishedAt IS NULL')
            ->orderBy('m.receivedAt', 'DESC')
            ->setParameter('user', $user)
            ->setParameter('addresses', $addresses)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
