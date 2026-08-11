<?php

namespace App\Repository\Mail;

use App\Entity\Mail\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * QueryBuilder because the ordering is an expression, not a field:
     * LOWER(COALESCE(email, username)) sorts an account that has no display
     * address under the one it logs in with, case-insensitively. findBy()
     * orders by mapped fields and can express neither the fallback nor the
     * case folding.
     *
     * @return iterable<Account>
     */
    public function findForUserOrderedByName(UserInterface $user): array
    {
        return $this->createQueryBuilder('account')
            ->addSelect('LOWER(COALESCE(account.email, account.username)) AS HIDDEN sortName')
            ->andWhere('account.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The same expression ordering as findForUserOrderedByName(), used as the
     * tiebreak behind the user's own arrangement.
     *
     * @return iterable<Account>
     */
    public function findForUserOrdered(UserInterface $user): array
    {
        return $this->createQueryBuilder('account')
            ->addSelect('LOWER(COALESCE(account.email, account.username)) AS HIDDEN sortName')
            ->andWhere('account.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('account.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * findForUserOrdered(), restricted to the accounts that are switched on.
     *
     * The sidebar's list, and the reason this exists rather than a findBy():
     * the sidebar used to order by sortOrder alone. sortOrder defaults to 0 and
     * is only ever rewritten by a drag or a delete, so on an install where
     * nobody has dragged anything every account sits at 0 and Postgres returns
     * them in whatever order it likes — which is not the order the settings
     * page showed, and not a stable order either. The name tiebreak is what
     * makes an untouched install agree with settings instead of disagreeing
     * arbitrarily.
     *
     * @return iterable<Account>
     */
    public function findActiveForUserOrdered(UserInterface $user): array
    {
        return $this->createQueryBuilder('account')
            ->addSelect('LOWER(COALESCE(account.email, account.username)) AS HIDDEN sortName')
            ->andWhere('account.usr = :usr')
            ->andWhere('account.isActive = true')
            ->setParameter('usr', $user)
            ->orderBy('account.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForUser(UserInterface $user): int
    {
        return $this->count(['usr' => $user]);
    }

    /**
     * Volume and last activity per account, for the admin sync overview.
     *
     * Raw SQL and correlated subqueries because a message attaches to an
     * account through its mailbox (IMAP) or through its thread (Gmail API), and
     * that OR is not something Doctrine's API can traverse. Counted in the
     * database rather than by hydrating accounts and walking their collections:
     * the whole page is three numbers per account, and the alternative loads
     * every message on the install to produce them.
     *
     * @return list<array<string,mixed>>
     */
    public function findSyncOverviewRows(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT
                a.id,
                a.email,
                a.is_active,
                (SELECT COUNT(*) FROM message_thread t WHERE t.account_id = a.id) AS threads,
                (SELECT COUNT(*)
                   FROM message m
                   LEFT JOIN mailbox mb ON m.mailbox_id = mb.id
                   LEFT JOIN message_thread mt ON m.thread_id = mt.id
                  WHERE mb.account_id = a.id OR mt.account_id = a.id) AS messages,
                (SELECT MAX(t.last_message_at) FROM message_thread t WHERE t.account_id = a.id) AS last_activity
             FROM account a
             ORDER BY a.email',
        );
    }

    /**
     * One account that has something encrypted stored on it, or null when the
     * install has none yet.
     *
     * Hydrating the result is the point: EncryptedStringType decrypts on read,
     * so a wrong APP_ENCRYPTION_KEY surfaces here as a conversion error rather
     * than silently later. See EncryptionKeyProbe.
     *
     * Criteria rather than a QueryBuilder because it is still Doctrine's own
     * API — matching() is what expresses an OR of three null tests without
     * dropping to DQL.
     */
    public function findOneWithStoredCredentials(): ?Account
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->orX(
                Criteria::expr()->neq('password', null),
                Criteria::expr()->neq('oauthRefreshToken', null),
                Criteria::expr()->neq('oauthAccessToken', null),
            ))
            ->setMaxResults(1);

        return $this->matching($criteria)->first() ?: null;
    }
}
