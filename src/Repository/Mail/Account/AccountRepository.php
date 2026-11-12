<?php

declare(strict_types=1);

namespace App\Repository\Mail\Account;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
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
     * Every account a JMAP push channel should be held open for.
     *
     * JMAP, push left on — the same switch the Gmail and Graph subscriptions
     * honour — and at least one service switched on (#575). Any one, not mail
     * alone: a JMAP server pushes one stream of state changes for mail,
     * calendars and contacts together, and JmapPushFanout hands each type to
     * a handler that checks its own switch. A Fastmail account kept for its
     * calendar still wants to hear about a moved meeting at once rather than
     * at the next sweep. QueryBuilder for the OR, which findBy() cannot say.
     * Fetched whole: the listener needs the credentials on each to open its
     * stream.
     *
     * @return list<Account>
     */
    public function findJmapForPush(): array
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.incomingProtocol = :jmap')
            ->andWhere('account.pushEnabled = true')
            ->andWhere('account.mailEnabled = true OR account.calendarEnabled = true OR account.contactsEnabled = true')
            ->setParameter('jmap', IncomingProtocol::Jmap)
            ->orderBy('account.id', 'ASC')
            ->getQuery()
            ->getResult();
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
     * findForUserOrdered(), restricted to the accounts whose mail is switched on.
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
     * @return list<Account>
     */
    public function findActiveForUserOrdered(UserInterface $user): array
    {
        return $this->createQueryBuilder('account')
            ->addSelect('LOWER(COALESCE(account.email, account.username)) AS HIDDEN sortName')
            ->andWhere('account.usr = :usr')
            ->andWhere('account.mailEnabled = true')
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
     * account through the folders that keep it (IMAP) or through its thread
     * (Gmail API) — counted once however many folders keep it — and
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
                a.mail_enabled,
                (SELECT COUNT(*) FROM message_thread t WHERE t.account_id = a.id) AS threads,
                (SELECT COUNT(*)
                   FROM message m
                   LEFT JOIN message_thread mt ON m.thread_id = mt.id
                  WHERE mt.account_id = a.id
                     OR EXISTS (SELECT 1 FROM message_location l JOIN mailbox mb ON mb.id = l.mailbox_id
                                 WHERE l.message_id = m.id AND mb.account_id = a.id)) AS messages,
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

    /**
     * Which of a user's mail-off accounts still hold mail, by id.
     *
     * Mail switched off keeps what was fetched (#575), and an account in that
     * state stays in the sidebar for as long as there is anything to read —
     * while one that never had mail, a Google account connected for its
     * calendar alone, has no business in a mail sidebar at all. This is the
     * line between the two.
     *
     * Only the switched-off accounts are asked about, because a switched-on one
     * is in the sidebar whatever it holds. EXISTS rather than a count: the
     * answer is yes or no, and idx_message_thread_account_last_message leads on
     * account_id, so the first thread found settles it.
     *
     * @return list<int>
     */
    public function findMailOffIdsHoldingMail(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('account')
            ->select('account.id')
            ->andWhere('account.usr = :usr')
            ->andWhere('account.mailEnabled = false')
            ->andWhere(sprintf('EXISTS (SELECT 1 FROM %s thread WHERE thread.account = account)', MessageThread::class))
            ->setParameter('usr', $user)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $rows);
    }

    /**
     * The ids of a user's mail-on accounts, as values a query can bind.
     *
     * @return list<int>
     */
    public function findActiveIdsForUser(User $user): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM account WHERE usr_id = ? AND mail_enabled = true',
            [$user->id],
        );

        return array_map(intval(...), $ids);
    }
}
