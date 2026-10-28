<?php

declare(strict_types=1);

namespace App\Repository\Label;

use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<LabelBinding>
 */
class LabelBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabelBinding::class);
    }

    /**
     * Every binding of this user's that IS an IMAP folder, with its account,
     * in one read — for the sidebar's folder marks (SidebarCounts::folderAccountsOf()).
     *
     * @return list<LabelBinding>
     */
    public function findFolderBindingsForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('a', 'm')
            ->join('b.account', 'a')
            ->join('b.mailbox', 'm')
            ->where('a.usr = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    public function findOneForLabelAndAccount(Label $label, Account $account): ?LabelBinding
    {
        return $this->findOneBy(['label' => $label, 'account' => $account]);
    }

    public function findOneByGmailLabelId(string $gmailLabelId, Account $account): ?LabelBinding
    {
        return $this->findOneBy(['gmailLabelId' => $gmailLabelId, 'account' => $account]);
    }

    public function findOneByGraphFolderId(string $graphFolderId, Account $account): ?LabelBinding
    {
        return $this->findOneBy(['graphFolderId' => $graphFolderId, 'account' => $account]);
    }

    public function findOneByJmapMailboxId(string $jmapMailboxId, Account $account): ?LabelBinding
    {
        return $this->findOneBy(['jmapMailboxId' => $jmapMailboxId, 'account' => $account]);
    }

    /**
     * An account's bindings to remote JMAP mailboxes, keyed by mailbox id.
     *
     * Keyed so a sync can resolve every mailbox id an Email names from one
     * read, rather than one query per id per message. QueryBuilder for the
     * same reason as findWithGraphFolderIdForAccount(): the filter is a
     * negated null.
     *
     * @return array<string, LabelBinding>
     */
    public function findByJmapMailboxIdForAccount(Account $account): array
    {
        /** @var list<LabelBinding> $bindings */
        $bindings = $this->createQueryBuilder('binding')
            ->addSelect('label')
            ->innerJoin('binding.label', 'label')
            ->where('binding.account = :account')
            ->andWhere('binding.jmapMailboxId IS NOT NULL')
            ->setParameter('account', $account)
            ->getQuery()
            ->getResult();

        $keyed = [];

        foreach ($bindings as $binding) {
            $keyed[(string) $binding->jmapMailboxId] = $binding;
        }

        return $keyed;
    }

    /**
     * Unbind every remote JMAP mailbox from an account's labels, keeping the
     * labels: what a switch away from JMAP leaves behind. See
     * MessageRepository::forgetJmapIds().
     */
    public function forgetJmapMailboxIds(Account $account): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->update(LabelBinding::class, 'b')
            ->set('b.jmapMailboxId', ':nothing')
            ->where('b.account = :account')
            ->andWhere('b.jmapMailboxId IS NOT NULL')
            ->setParameter('nothing', null)
            ->setParameter('account', $account)
            ->getQuery()
            ->execute();
    }

    /**
     * QueryBuilder for `graphFolderId IS NOT NULL`: findBy() can ask for null,
     * but not for its negation.
     *
     * @return list<LabelBinding>
     */
    public function findWithGraphFolderIdForAccount(Account $account): array
    {
        return $this->createQueryBuilder('binding')
            ->where('binding.account = :account')
            ->andWhere('binding.graphFolderId IS NOT NULL')
            ->setParameter('account', $account)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every binding on an account, in JMAP Mailbox display order: system
     * labels first by fixed sortOrder, then custom labels alphabetically.
     *
     * @return list<LabelBinding>
     */
    public function findForAccountOrdered(int $accountId): array
    {
        return $this->orderedQueryBuilder($accountId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $ids binding ids
     *
     * @return list<LabelBinding>
     */
    public function findForAccountAndIds(int $accountId, array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        return $this->orderedQueryBuilder($accountId)
            ->andWhere('binding.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Binding ids on an account keyed by label id — lets callers translate
     * between the two id spaces without hydrating entities.
     *
     * "Without hydrating entities" is the reason it is hand-written: findBy()
     * returns bindings, and the JMAP mappers want two integers per row to
     * translate ids with.
     *
     * @return array<int, int> labelId => bindingId
     */
    public function bindingIdsByLabelId(int $accountId): array
    {
        $rows = $this->createQueryBuilder('binding')
            ->select('IDENTITY(binding.label) AS labelId', 'binding.id AS bindingId')
            ->where('binding.account = :account')
            ->setParameter('account', $accountId)
            ->getQuery()
            ->getScalarResult();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['labelId']] = (int) $row['bindingId'];
        }

        return $map;
    }

    /**
     * The shared shape of every ordered binding read.
     *
     * Hand-written because both halves are beyond findBy(): the label is
     * fetch-joined (every caller renders its name, so lazy loading is an N+1
     * per mailbox), and the tiebreak is LOWER(name) rather than a field, so
     * database collation cannot put "Zebra" before "apple".
     */
    private function orderedQueryBuilder(int $accountId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('binding')
            ->addSelect('label')
            ->addSelect('LOWER(label.name) AS HIDDEN sortName')
            ->innerJoin('binding.label', 'label')
            ->where('binding.account = :account')
            ->setParameter('account', $accountId)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC');
    }
}
