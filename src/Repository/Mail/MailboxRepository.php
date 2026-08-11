<?php

namespace App\Repository\Mail;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Mailbox>
 */
class MailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailbox::class);
    }

    /** Doctrine's own findBy(), keyed in PHP — the index is not a query. */
    public function findIndexedByFullPath(Account $account): array
    {
        $mailboxes = $this->findBy(['account' => $account]);
        $indexed = [];

        foreach ($mailboxes as $mailbox) {
            $indexed[$mailbox->fullPath] = $mailbox;
        }

        return $indexed;
    }

    public function findTrashMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::TRASH]);
    }

    public function findArchiveMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::ARCHIVE]);
    }

    public function findSentMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::SENT]);
    }

    public function findDraftMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::DRAFTS]);
    }

    /**
     * The oldest full-listing this account's folders have between them, or null
     * if any of them has never had one.
     *
     * This is the coverage guarantee behind remote deletion. A row that went
     * missing from one folder may simply be in another, so the only moment its
     * absence means anything is once *every* folder has been listed since — and
     * the folder that was listed longest ago is what that comes down to.
     *
     * Null is the important answer and is why this returns one rather than
     * skipping the folders it cannot speak for. A single never-swept folder
     * means the account has no such moment yet, and nothing may be erased on
     * the strength of the folders that have been looked in.
     *
     * Sync-disabled folders are excluded because nothing ever lists them, so
     * including them would make the answer permanently null. The cost is stated
     * where it lands: a message moved by hand into a folder the user has turned
     * sync off for is, to plMail, a message that left the account.
     */
    public function earliestSweepAcross(Account $account): ?\DateTimeImmutable
    {
        /** @var list<array{sweptAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('mailbox')
            ->select('mailbox.sweptAt')
            ->where('mailbox.account = :account')
            ->andWhere('mailbox.isSyncEnabled = :enabled')
            ->setParameter('account', $account)
            ->setParameter('enabled', true)
            ->getQuery()
            ->getArrayResult();

        if (0 === count($rows)) {
            return null;
        }

        $earliest = null;

        foreach ($rows as $row) {
            $sweptAt = $row['sweptAt'];

            if (null === $sweptAt) {
                return null;
            }

            if (null === $earliest || $sweptAt < $earliest) {
                $earliest = $sweptAt;
            }
        }

        return $earliest;
    }

    /**
     * Mailboxes an IDLE supervisor should hold a connection for.
     *
     * QueryBuilder on two counts: whether the owning account is still active is
     * a field of Account, which findBy() cannot reach, and the account is
     * fetch-joined because the supervisor opens a connection with its
     * credentials for every row — leaving it lazy is an N+1 across the whole
     * install at boot.
     */
    public function findIdleEnabledAndSyncEnabled(): array
    {
        $queryBuilder = $this->createQueryBuilder('mailbox');

        $queryBuilder
            ->innerJoin('mailbox.account', 'account')
            ->addSelect('account')
            ->andWhere('mailbox.isIdleEnabled = :isIdleEnabled')
            ->andWhere('mailbox.isSyncEnabled = :isSyncEnabled')
            ->andWhere('account.isActive = :isActive')
            ->setParameter('isIdleEnabled', true)
            ->setParameter('isSyncEnabled', true)
            ->setParameter('isActive', true);

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * QueryBuilder for the join to Account and for the id-only projection —
     * the caller compares sets of ids and has no use for a Mailbox.
     */
    public function getIdsOfActiveInboxMailboxesForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('mailbox')
            ->select('mailbox.id')
            ->leftJoin('mailbox.account', 'account')
            ->where('account.isActive = :isActive')
            ->andWhere('account.usr = :usr')
            ->andWhere('mailbox.specialUse = :inbox')
            ->setParameter('isActive', true)
            ->setParameter('usr', $user)
            ->setParameter('inbox', MailboxSpecialUse::INBOX)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
