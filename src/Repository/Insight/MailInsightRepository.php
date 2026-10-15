<?php

declare(strict_types=1);

namespace App\Repository\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

class MailInsightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailInsight::class);
    }

    /**
     * The upsert's read half — see InsightHarvester.
     *
     * A row persisted in this unit of work and not flushed yet counts as much
     * as one in the table. The sync harvests a whole batch and flushes once
     * (ExtractInsightsStep), so when the shipping and the delivery mail for one
     * parcel arrived in the same batch, each found nothing in the table and
     * each inserted, and the one flush broke uniq_mail_insight_account_dedupe.
     * That closed the entity manager under the rest of the sync. A first sync
     * is thousands of mails in batches of fifty, so a fresh install hit it
     * within minutes.
     *
     * Scanned rather than queried: a pending row has no id and is not in the
     * table, so the unit of work is the only place it can be found.
     */
    public function findOneByDedupe(Account $account, string $dedupeKey): ?MailInsight
    {
        foreach ($this->getEntityManager()->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof MailInsight
                && $dedupeKey === $pending->dedupeKey
                && $account->id === $pending->account->id) {
                return $pending;
            }
        }

        return $this->findOneBy(['account' => $account, 'dedupeKey' => $dedupeKey]);
    }

    /**
     * Dated insights inside the window: a little of the recent past — a
     * parcel that landed this morning is still the answer to "where is my
     * parcel?" — and the near future. Undismissed, active accounts only.
     *
     * @return list<MailInsight> soonest first
     */
    public function upcomingForUser(
        UserInterface $user,
        \DateTimeImmutable $now,
        int $daysAhead = 14,
        int $hoursBack = 18,
        int $limit = 30,
    ): array {
        return $this->createQueryBuilder('i')
            ->join('i.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('i.dismissedAt IS NULL')
            ->andWhere('i.happensAt IS NOT NULL')
            ->andWhere('i.happensAt >= :from')
            ->andWhere('i.happensAt <= :until')
            ->orderBy('i.happensAt', 'ASC')
            // Ties here are the common case, not the exotic one: a parcel's ETA
            // is a DAY, so every parcel due on the same day sits at that day's
            // midnight, and which of them came first was left to the planner.
            // With a cap on the list that also decided which one fell off the
            // end. Newest first among equals, matching the undated list below.
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('user', $user)
            ->setParameter('from', $now->modify(sprintf('-%d hours', $hoursBack)))
            ->setParameter('until', $now->modify(sprintf('+%d days', $daysAhead)))
            ->getQuery()
            ->getResult();
    }

    /**
     * The undated family — GitHub activity and whatever joins it. Recency is
     * the only timeline these have, so the window is on how recent the card's
     * MAIL is, and the newest speak first.
     *
     * The mail's receivedAt and not the row's createdAt, which says when the
     * harvester ran. The two agree while the harvester runs as mail arrives and
     * part company the moment it re-reads old mail: the insights backfill
     * creates its cards now, so a month-old notification would otherwise be a
     * card of the last minute and sit among the newest twenty for a week. The
     * card links the NEWEST mail of its series (see InsightHarvester), so this
     * is the date of the latest word on the thing.
     *
     * A card whose mail was deleted has no link left (SET NULL) and falls back
     * to its row's date — the only date it has.
     *
     * A one-time code is the exception to the week. It is undated only when its
     * mail states no expiry (OtpExtractor leaves happensAt null rather than
     * guess one, so a code that might still be good is not retired early), and
     * the radar then has nothing to drop it by: a code from last Monday would
     * stay on the page, readable, until the week ran out. It is offered for
     * $otpMinutes of its mail instead: long enough to be copied from here, and
     * a guess about how long a code lives that errs towards showing too little,
     * which is the cheaper way to be wrong with a secret on the page.
     *
     * @return list<MailInsight>
     */
    public function recentUndatedForUser(
        UserInterface $user,
        \DateTimeImmutable $now,
        int $daysBack = 7,
        int $limit = 20,
        int $otpMinutes = 15,
    ): array {
        return $this->createQueryBuilder('i')
            ->join('i.account', 'a')
            ->leftJoin('i.message', 'm')
            ->addSelect('COALESCE(m.receivedAt, i.createdAt) AS HIDDEN seenAt')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('i.dismissedAt IS NULL')
            ->andWhere('i.happensAt IS NULL')
            ->andWhere('COALESCE(m.receivedAt, i.createdAt) >= :from')
            ->andWhere('(i.kind <> :otp OR COALESCE(m.receivedAt, i.createdAt) >= :otpFrom)')
            ->orderBy('seenAt', 'DESC')
            // Same reason, and likelier still: these are written by the
            // harvester as it walks a batch of mail, so a run that reads twenty
            // notifications stamps them all within the same second.
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('user', $user)
            ->setParameter('from', $now->modify(sprintf('-%d days', $daysBack)))
            ->setParameter('otp', InsightKind::Otp->value)
            ->setParameter('otpFrom', $now->modify(sprintf('-%d minutes', $otpMinutes)))
            ->getQuery()
            ->getResult();
    }

    /**
     * Every insight a conversation carries, for the card above the message.
     * Dismissed rows included on purpose: dismissal hides a card from the
     * upcoming panel, not from the mail that plainly states the fact.
     *
     * @return list<MailInsight>
     */
    public function forThread(MessageThread $thread): array
    {
        return $this->findBy(['thread' => $thread], ['happensAt' => 'ASC', 'id' => 'ASC']);
    }
}
