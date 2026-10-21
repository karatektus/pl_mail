<?php

declare(strict_types=1);

namespace App\Repository\Note;

use App\Entity\Note\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    /**
     * Every note of one user, pinned first and then newest-changed first — the order a notes wall
     * is drawn in, so a client that sorts nothing still shows the right thing.
     *
     * @return list<Note>
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user], ['pinned' => 'DESC', 'updatedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Notes (not in the bin) that link to one thing — a contact's or an event's
     * "Notes" section. An event link may name one occurrence of a series
     * ("12_20261020T090000Z", the JMAP occurrence id); it counts for the series.
     *
     * @return list<Note>
     */
    public function findLinkedTo(UserInterface $user, string $type, string $id): array
    {
        return array_values(array_filter(
            $this->findForUser($user),
            static function (Note $note) use ($type, $id): bool {
                if (null !== $note->trashedAt) {
                    return false;
                }
                foreach ($note->links as $link) {
                    $linkId = (string) ($link['id'] ?? '');
                    if (($link['type'] ?? '') === $type && ($linkId === $id || ('event' === $type && str_starts_with($linkId, $id . '_')))) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * The event (series) ids any of this user's notes link to, not
     * counting notes in the bin. An occurrence link ("12_20261020T090000Z")
     * counts for its series.
     *
     * @return array<int, true>
     */
    public function linkedEventIds(UserInterface $user): array
    {
        $out = [];
        foreach ($this->findForUser($user) as $note) {
            if (null !== $note->trashedAt) {
                continue;
            }
            foreach ($note->links as $link) {
                if ('event' !== ($link['type'] ?? '')) {
                    continue;
                }
                $series = explode('_', (string) ($link['id'] ?? ''), 2)[0];
                if (ctype_digit($series)) {
                    $out[(int) $series] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Notes whose reminder has come due and not been sent, newest due last,
     * not older than $since (a reminder missed by more than that is dropped
     * rather than delivered hours late), and not in the bin.
     *
     * @return list<Note>
     */
    public function findDueReminders(\DateTimeImmutable $now, \DateTimeImmutable $since): array
    {
        /** @var list<Note> */
        return $this->createQueryBuilder('n')
            ->where('n.reminderAt IS NOT NULL')
            ->andWhere('n.reminderSentAt IS NULL')
            ->andWhere('n.reminderAt <= :now')
            ->andWhere('n.reminderAt > :since')
            ->andWhere('n.trashedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('since', $since)
            ->orderBy('n.reminderAt', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Mark one reminder sent, unless another run already has. True when this
     * call won the claim — the only run that then sends it.
     */
    public function claimReminder(Note $note, \DateTimeImmutable $now): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE user_note SET reminder_sent_at = :now WHERE id = :id AND reminder_sent_at IS NULL AND reminder_at = :at',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'id'  => $note->id,
                'at'  => $note->reminderAt?->format('Y-m-d H:i:s'),
            ],
        );
    }
}
