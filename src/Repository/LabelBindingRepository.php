<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Label;
use App\Entity\LabelBinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LabelBinding>
 */
class LabelBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabelBinding::class);
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

    /**
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
