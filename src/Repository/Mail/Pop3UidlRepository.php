<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Pop3Uidl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The POP3 sync's memory of which messages it already has. See Pop3Uidl.
 *
 * The writes are plain SQL upserts rather than entities, because a sync
 * touches hundreds of these at a time and never needs one as an object: it
 * asks "which of these UIDLs do I know, and how", and says "these are stored
 * now". Hydrating a row per UIDL to answer that would be the whole cost of a
 * sync that found nothing new.
 *
 * @extends ServiceEntityRepository<Pop3Uidl>
 */
class Pop3UidlRepository extends ServiceEntityRepository
{
    /** Rows deleted per statement when pruning; well under PostgreSQL's parameter limit. */
    private const int CHUNK = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pop3Uidl::class);
    }

    /**
     * Everything known about this account's server mailbox.
     *
     * @return array<string, array{stored: bool, attempts: int}> by UIDL
     */
    public function ledgerFor(Account $account): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT uidl, stored_at IS NOT NULL AS stored, attempts FROM pop3_uidl WHERE account_id = ?',
            [(int) $account->id],
        );

        $ledger = [];

        foreach ($rows as $row) {
            $ledger[(string) $row['uidl']] = [
                'stored'   => true === (bool) $row['stored'],
                'attempts' => (int) $row['attempts'],
            ];
        }

        return $ledger;
    }

    /**
     * These messages are here now: stored by this sync, or recognised as
     * already held. Committed straight away, because the delete marks that
     * may follow are only ever made for what this has written.
     *
     * @param list<string> $uidls
     */
    public function recordStored(Account $account, array $uidls): void
    {
        $connection = $this->getEntityManager()->getConnection();

        foreach (array_unique($uidls) as $uidl) {
            $connection->executeStatement(
                'INSERT INTO pop3_uidl (account_id, uidl, stored_at, attempts) VALUES (?, ?, now(), 0)'
                . ' ON CONFLICT (account_id, uidl) DO UPDATE SET stored_at = COALESCE(pop3_uidl.stored_at, EXCLUDED.stored_at)',
                [(int) $account->id, $uidl],
            );
        }
    }

    /** One more failed attempt at a message that is not here. */
    public function recordFailure(Account $account, string $uidl): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO pop3_uidl (account_id, uidl, stored_at, attempts) VALUES (?, ?, NULL, 1)'
            . ' ON CONFLICT (account_id, uidl) DO UPDATE SET attempts = pop3_uidl.attempts + 1',
            [(int) $account->id, $uidl],
        );
    }

    /**
     * Forget every UIDL the server no longer lists.
     *
     * This is what bounds the table: a message deleted from the server — by
     * this sync's delete mode, by the user's phone, by the provider's
     * retention — will never be listed again, so its row says nothing any
     * more. What is left is exactly the server's mailbox.
     *
     * Safe in the one direction that matters. A row dropped wrongly costs a
     * message fetched again (a duplicate); it can never cost a message.
     *
     * @param list<string>                                           $onServer every UIDL the server listed this session
     * @param array<string, array{stored: bool, attempts: int}>|null $known    the ledger, when the caller has just read it
     *
     * @return int rows removed
     */
    public function prune(Account $account, array $onServer, ?array $known = null): int
    {
        $listed = array_flip($onServer);
        $gone   = [];

        foreach (array_keys($known ?? $this->ledgerFor($account)) as $uidl) {
            if (false === isset($listed[$uidl])) {
                $gone[] = (string) $uidl;
            }
        }

        $removed = 0;

        foreach (array_chunk($gone, self::CHUNK) as $chunk) {
            $removed += (int) $this->getEntityManager()->getConnection()->executeStatement(
                'DELETE FROM pop3_uidl WHERE account_id = ? AND uidl IN (?)',
                [(int) $account->id, $chunk],
                [ParameterType::INTEGER, ArrayParameterType::STRING],
            );
        }

        return $removed;
    }

    /** Forget the whole mailbox: the account stops reading this server over POP3. */
    public function forget(Account $account): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM pop3_uidl WHERE account_id = ?',
            [(int) $account->id],
        );
    }
}
