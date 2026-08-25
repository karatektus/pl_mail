<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Removes a demo visitor and everything that was theirs.
 *
 * This project had no user-deletion path before demo mode, because it never
 * needed one: a self-hosted install's users are its household, and they leave
 * by having their password changed rather than by being erased. A demo mints a
 * user per visitor, so it needs the operation that did not exist.
 *
 * The owned classes are discovered from Doctrine's own metadata rather than
 * listed here, and that is the whole design. Twenty entities point at User
 * today, under two different property names — `usr` almost everywhere and
 * `reportedBy` on InsightReport — and a hand-written list would be correct
 * until the next entity was added, at which point the reaper would start
 * failing on a foreign key at three in the morning with nothing saying why.
 * Asking the mapping means an entity added next year is covered by having been
 * mapped, which is the only thing its author is guaranteed to have done.
 *
 * Deletion order is left to the UnitOfWork, which computes a commit order from
 * the associations between the classes. That is what makes this safe without a
 * hand-maintained dependency graph: a CalendarEventOccurrence is removed before
 * the CalendarEvent it hangs off because Doctrine can see that it must be.
 *
 * Rows that reach the user only through their account — messages, threads,
 * mailboxes, parts — are not enumerated. Removing the Account takes them, which
 * is the same path Settings → Accounts → Delete has always used.
 */
final readonly class DemoUserEraser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DemoMode               $demoMode,
    ) {
    }

    /**
     * Deletes the user and their data.
     *
     * Refuses anyone the demo did not mint. This is the one destructive
     * operation in the feature, it is driven by a timer rather than by a
     * person, and the cost of a bug in the predicate that selects its input is
     * somebody's mailbox — so the predicate is checked again here, at the point
     * of no return, rather than trusted from the caller.
     */
    public function erase(User $user): void
    {
        if (false === $this->demoMode->ownsAddress($user->email)) {
            throw new \LogicException(sprintf(
                'Refusing to erase "%s": not a demo-provisioned user.',
                (string) $user->email,
            ));
        }

        foreach ($this->ownedClasses() as $class => $field) {
            $rows = $this->entityManager->getRepository($class)->findBy([$field => $user]);

            foreach ($rows as $row) {
                $this->entityManager->remove($row);
            }
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Every mapped class holding a to-one association to User, and the field
     * holding it.
     *
     * Mapped superclasses and embeddables are skipped: they have no table of
     * their own, so there is nothing there to delete and asking for a
     * repository would throw.
     *
     * @return array<class-string, string>
     */
    private function ownedClasses(): array
    {
        $owned = [];

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (false === $metadata instanceof ClassMetadata) {
                continue;
            }

            if (true === $metadata->isMappedSuperclass || User::class === $metadata->getName()) {
                continue;
            }

            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if (User::class !== $metadata->getAssociationTargetClass($field)) {
                    continue;
                }

                if (false === $metadata->isSingleValuedAssociation($field)) {
                    continue;
                }

                // First one wins, and no class is expected to have two. If one
                // ever does, the second is reachable through the first's owner
                // or is not this user's to delete.
                $owned[$metadata->getName()] = $field;

                break;
            }
        }

        return $owned;
    }
}
