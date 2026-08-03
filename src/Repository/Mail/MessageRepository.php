<?php

namespace App\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Query\CompiledFilter;
use App\Service\Graph\GraphMessageBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class MessageRepository extends ServiceEntityRepository
{
    /**
     * Content types that mean "there is an invite in here".
     *
     * @var list<string>
     */
    private const array CALENDAR_TYPES = ['text/calendar', 'application/ics'];

    /**
     * Messages that could plausibly carry an event.
     *
     * A mailbox is mostly newsletters, and parsing every one to find the few
     * per cent that are bookings is work nobody gets back. Three signals, one
     * per extractor: a text/calendar part (an invite, on IMAP or Gmail), the
     * synthetic Graph meeting header (an invite with no part to find), and
     * schema.org markup in the raw body (a reservation).
     *
     * Raw DBAL because none of the three is expressible otherwise: jsonb key
     * existence has no DQL operator and no registered function, and the rest
     * is an EXISTS correlated to a second entity. Written as jsonb_exists()
     * rather than the `?` operator that means the same thing — DBAL reads a
     * bare `?` as a positional placeholder and refuses the query — and cast,
     * because message.headers is a json column rather than jsonb. The
     * parameters are namespaced so they cannot collide if this is ever
     * combined with a compiled filter, as MessageRepository::matchingIds does.
     */
    /**
     * The WHERE both candidate queries share, so a change to what counts as a
     * candidate cannot land in one and not the other. Built by concatenation
     * rather than by editing a finished query — the first version pulled the
     * LIMIT off with str_replace and silently stopped matching the moment the
     * heredoc's indentation changed.
     */
    private const string EXTRACTION_CANDIDATE_WHERE = <<<'SQL'
        m.id > :extAfterId
        AND (
              EXISTS (
                  SELECT 1 FROM message_part p
                  WHERE p.message_id = m.id
                    AND LOWER(p.content_type) IN (:extCalendarTypes)
              )
           OR jsonb_exists(m.headers::jsonb, :extMeetingHeader)
           OR m.body_html LIKE :extJsonLd
        )
        SQL;

    public function countExtractionCandidates(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM message m WHERE ' . self::EXTRACTION_CANDIDATE_WHERE,
            $this->candidateParameters(0),
            $this->candidateTypes(),
        );
    }

    /**
     * @return list<Message>
     */
    public function extractionCandidates(int $afterId, int $limit): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT m.id FROM message m WHERE ' . self::EXTRACTION_CANDIDATE_WHERE
            . ' ORDER BY m.id ASC LIMIT ' . max(1, $limit),
            $this->candidateParameters($afterId),
            $this->candidateTypes(),
        );

        if (0 === count($ids)) {
            return [];
        }

        return $this->createQueryBuilder('message')
            ->where('message.id IN (:ids)')
            ->setParameter('ids', array_map('intval', $ids))
            ->orderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateParameters(int $afterId): array
    {
        return [
            'extAfterId'       => $afterId,
            'extCalendarTypes' => self::CALENDAR_TYPES,
            'extMeetingHeader' => GraphMessageBuilder::MEETING_TYPE_HEADER,
            'extJsonLd'        => '%application/ld+json%',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateTypes(): array
    {
        return [
            'extAfterId'       => ParameterType::INTEGER,
            'extCalendarTypes' => ArrayParameterType::STRING,
            'extMeetingHeader' => ParameterType::STRING,
            'extJsonLd'        => ParameterType::STRING,
        ];
    }

    /**
     * Which of these messages a compiled filter matches.
     *
     * This is the single place a rule's conditions are ever evaluated. Letting
     * Postgres answer means there is exactly one implementation of what a
     * filter means — an in-memory twin was tried and deleted, because two
     * implementations can drift and the symptom is mail quietly filed in the
     * wrong place. It also makes `text` usable in rules: search_vector is a
     * STORED generated column, so full-text works here and could never be
     * reproduced faithfully in PHP.
     *
     * The messages must already be flushed — they are, since rules run after
     * the id-granting flush in every sync path.
     *
     * @param list<int> $messageIds
     *
     * @return list<int> ids that match, in the order given
     */
    public function matchingIds(array $messageIds, CompiledFilter $filter): array
    {
        if (0 === count($messageIds)) {
            return [];
        }

        $sql = sprintf(
            'SELECT m.id FROM message m WHERE m.id IN (:ruleMessageIds) AND (%s)',
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleMessageIds'] = $messageIds;

        $types = $filter->parameterTypes();
        $types['ruleMessageIds'] = ArrayParameterType::INTEGER;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $types)
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * How many of a user's messages a compiled filter matches.
     *
     * Powers the live "matches N messages" readout while a rule is being
     * written. Capped, because the only question being answered is "is this
     * filter roughly right" — an exact count over a large mailbox costs a full
     * scan to tell the author something they do not need.
     *
     * @return array{count: int, capped: bool}
     */
    public function countMatchingForUser(User $user, CompiledFilter $filter, int $cap = 500): array
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM (
                 SELECT 1 FROM message m
                 JOIN account a ON a.id = m.account_id
                 WHERE a.usr_id = :ruleUserId AND (%s)
                 LIMIT :ruleCap
             ) probe',
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleUserId'] = $user->id;
        $parameters['ruleCap'] = $cap + 1;

        $count = (int) $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $filter->parameterTypes())
            ->fetchOne();

        return [
            'count' => min($count, $cap),
            'capped' => $count > $cap,
        ];
    }

    /**
     * One page of a user's messages matching a filter, for applying a rule to
     * mail that arrived before it existed.
     *
     * Keyset pagination rather than a cap: applying a rule must reach every
     * matching message, and a mailbox can hold more of them than fits in
     * memory. Paging by id also survives the run's own writes — an OFFSET walk
     * would skip messages as the rows it already acted on stop matching.
     *
     * @return list<int> ascending, empty when the walk is finished
     */
    public function findIdsMatchingForUser(User $user, CompiledFilter $filter, int $afterId = 0, int $batchSize = 200): array
    {
        $sql = sprintf(
            'SELECT m.id FROM message m
             JOIN account a ON a.id = m.account_id
             WHERE a.usr_id = :ruleUserId AND m.id > :ruleAfterId AND (%s)
             ORDER BY m.id ASC
             LIMIT :ruleBatchSize',
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleUserId'] = $user->id;
        $parameters['ruleAfterId'] = $afterId;
        $parameters['ruleBatchSize'] = $batchSize;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $filter->parameterTypes())
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /**
     * Ids of messages that have a header bag, oldest first — the backfill
     * cursor for header normalisation.
     *
     * @return list<int>
     */
    public function findIdsWithHeaders(int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.headers IS NOT NULL')
            ->andWhere('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Ids of every message, oldest first — the backfill cursor for tasks that
     * rewrite a column present on every row (address normalisation).
     *
     * @return list<int>
     */
    public function findIdsAfter(int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    public function findByIds(array $ids): array
    {
        if (0 === count($ids)) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findSyncedUids(Mailbox $mailbox): array
    {
        return $this->createQueryBuilder('m')
            ->select('m.imapUid')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function findSyncedGmailIdsForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->select('m.gmailId')
            ->innerJoin('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId IS NOT NULL')
            ->setParameter('usr', $user)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Joined via thread since Gmail-API messages carry no mailbox.
     */
    public function findOneByMessageIdsForAccount(array $messageIds, Account $account): ?Message
    {
        if (count($messageIds) === 0) {
            return null;
        }

        return $this->createQueryBuilder('message')
            ->innerJoin('message.thread', 'thread')
            ->where('thread.account = :account')
            ->andWhere('message.messageId IN (:messageIds)')
            ->setParameter('account', $account)
            ->setParameter('messageIds', $messageIds)
            ->orderBy('message.receivedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsWithFromAddressInThread(string $fromAddress, MessageThread $thread): bool
    {
        return $this->existsWithAnyFromAddressInThread([$fromAddress], $thread);
    }

    /**
     * Does the thread contain a message sent by any of these addresses?
     *
     * Threading passes the candidate's sender *and* its recipients: a reply from
     * someone new to the conversation shares no sender with it, but its To/Cc
     * almost always names someone who has already posted. Checking senders only
     * would reject those replies.
     *
     * @param list<string> $addresses
     */
    public function existsWithAnyFromAddressInThread(array $addresses, MessageThread $thread): bool
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn(string $address): string => mb_strtolower(trim($address)), $addresses),
            static fn(string $address): bool => '' !== $address,
        )));

        if (0 === count($normalized)) {
            return false;
        }

        $result = $this->createQueryBuilder('m')
            ->select('1')
            ->where('m.thread = :thread')
            ->andWhere('LOWER(m.fromAddress) IN (:addresses)')
            ->setParameter('thread', $thread)
            ->setParameter('addresses', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }

    public function countUnseenForMailbox(Mailbox $mailbox): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.seenAt IS NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTotalForMailbox(Mailbox $mailbox): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Label-based: covers Gmail drafts too (no mailbox to join through).
     */
    public function findDrafts(): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.labels', 'l')
            ->where('l.role = :drafts')
            ->setParameter('drafts', LabelRole::Drafts)
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Stream every message belonging to an account — via its mailbox (IMAP)
     * or its thread (Gmail-API messages carry no mailbox row).
     *
     * @return iterable<Message>
     */
    public function iterateForAccount(Account $account): iterable
    {
        return $this->createQueryBuilder('message')
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('mailbox.account = :account OR thread.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->toIterable();
    }

    /**
     * The account's own copy of a message (via mailbox or thread ownership)
     * by canonical RFC Message-ID — the enrichment target for Gmailify dedup.
     */
    public function findOneForAccountByMessageId(Account $account, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('mailbox.account = :account OR thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A Gmail-imported message on this account with the given RFC Message-ID
     * that has no IMAP location yet — claimable by the IMAP syncer when the
     * server-side copy shows up.
     */
    public function findGmailOnlyByMessageId(Account $account, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->innerJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('message.gmailId IS NOT NULL')
            ->andWhere('message.imapUid IS NULL')
            ->andWhere('thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return iterable<Message> */
    public function iterateForRecategorization(Account $account, bool $includeCategorized): iterable
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.account = :account')
            ->setParameter('account', $account);

        if (false === $includeCategorized) {
            $qb->andWhere('m.category IS NULL');
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * @return list<string>
     */
    public function findSyncedGraphIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.graphId')
            ->join('m.account', 'a')
            ->andWhere('a.usr = :user')
            ->andWhere('m.graphId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map('strval', array_column($rows, 'graphId')));
    }

    /**
     * JMAP Email/get: fetch by id, scoped to the account so a foreign id can
     * never resolve. Labels and parts are eager-joined because the mapper
     * touches both for every message.
     *
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    public function findByAccountAndIds(int $accountId, array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->addSelect('l', 'p')
            ->leftJoin('m.labels', 'l')
            ->leftJoin('m.messageParts', 'p')
            ->where('m.account = :account')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('account', $accountId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
