<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Encrypted mail and the decrypted bodies browsers hand back for it: what is
 * still waiting for one, and what has one to forget.
 */
class MessageDecryptedRepository extends ServiceEntityRepository
{
    /**
     * The kinds of encryption a browser can open and send the plaintext of.
     * One list for the web page and the phones' JMAP method, so the two cannot
     * come to disagree about what is still pending.
     */
    private const array DECRYPTABLE = [
        MessageCrypto::PgpEncrypted,
        MessageCrypto::PgpInlineEncrypted,
        MessageCrypto::SmimeEncrypted,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Encrypted mail of these accounts still without its decrypted body,
     * newest first. Sealed drafts are the user's own and never wait.
     *
     * @param list<Account> $accounts
     *
     * @return list<Message>
     */
    public function findAwaitingDecryption(array $accounts, int $limit): array
    {
        /** @var list<Message> */
        return $this->getEntityManager()->createQuery(
            'SELECT m FROM ' . Message::class . ' m
              WHERE m.account IN (:accounts) AND m.crypto IN (:crypto)
                AND m.plaintextSource = :none AND m.sealedDraft = false
              ORDER BY m.receivedAt DESC',
        )
            ->setParameter('accounts', $accounts)
            ->setParameter('crypto', self::DECRYPTABLE)
            ->setParameter('none', PlaintextSource::None)
            ->setMaxResults($limit)
            ->getResult();
    }

    /**
     * The same for one account, as ids only.
     *
     * @return list<string>
     */
    public function findIdsAwaitingDecryption(Account $account, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT m.id FROM ' . Message::class . ' m
              WHERE m.account = :account AND m.crypto IN (:crypto)
                AND m.plaintextSource = :none AND m.sealedDraft = false
              ORDER BY m.receivedAt DESC',
        )
            ->setParameter('account', $account)
            ->setParameter('crypto', self::DECRYPTABLE)
            ->setParameter('none', PlaintextSource::None)
            ->setMaxResults($limit)
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * A batch of an account's mail whose plaintext a browser supplied, oldest
     * id first, for forgetting it.
     *
     * @return list<Message>
     */
    public function findClientDecrypted(Account $account, int $limit): array
    {
        /** @var list<Message> */
        return $this->getEntityManager()->createQuery(
            'SELECT m FROM ' . Message::class . ' m WHERE m.account = :account AND m.plaintextSource = :client ORDER BY m.id ASC',
        )
            ->setParameter('account', $account)
            ->setParameter('client', PlaintextSource::Client)
            ->setMaxResults($limit)
            ->getResult();
    }
}
