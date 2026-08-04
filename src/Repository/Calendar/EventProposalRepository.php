<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\EventProposal;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventProposal>
 */
class EventProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventProposal::class);
    }

    /**
     * Whether this message has already offered something.
     *
     * Asked before a detection run writes, and the reason it is "any proposal"
     * rather than "a proposal at this instant": re-running an improved parser
     * over a message whose card is still on screen would otherwise add a second
     * card beside the first, and the user has judged neither. One guess per
     * message, until they answer it.
     *
     * The unique constraint is the other half — see the entity. This check is a
     * read on data a concurrent backfill may be about to change.
     */
    public function hasAnyFor(Message $message): bool
    {
        return null !== $this->findOneBy(['message' => $message]);
    }

    /**
     * Every proposal a conversation carries, in one query.
     *
     * Per thread rather than per message for the reason
     * EventSourceLinkRepository::findInvitesForThread() gives at length: the
     * card is drawn by a partial included once per message, so a lookup keyed on
     * the message is an indexed query per row on every thread anybody opens, to
     * answer "no" for nearly all of them.
     *
     * The message is fetch-joined because the card is keyed on its id and the
     * accept form posts to it — leaving it lazy is a second query per card.
     *
     * @return list<EventProposal>
     */
    public function findForThread(MessageThread $thread): array
    {
        return $this->createQueryBuilder('proposal')
            ->addSelect('message')
            ->join('proposal.message', 'message')
            ->where('message.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('proposal.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The same answer for a message threading never joined to anything.
     *
     * @return list<EventProposal>
     */
    public function findForMessage(Message $message): array
    {
        return $this->createQueryBuilder('proposal')
            ->addSelect('message')
            ->join('proposal.message', 'message')
            ->where('proposal.message = :message')
            ->setParameter('message', $message)
            ->orderBy('proposal.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
