<?php

declare(strict_types=1);

namespace App\Repository\Mail\TrustedImageSender;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\TrustedImageSender;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The allowlist behind "Always show images from …".
 *
 * An address is trusted by an entry for itself, by a pattern that matches it
 * (`invoice+*@stripe.com`) or by one for its domain (`*@stripe.com`, #807).
 * Addresses and domains are looked up together by value. Patterns cannot be
 * looked up that way, so a user's patterns are read in a second query and
 * tried in PHP, only when an address is left that nothing else trusts. A user
 * has a handful of them at most; they are typed in by hand.
 *
 * @extends ServiceEntityRepository<TrustedImageSender>
 */
class TrustedImageSenderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedImageSender::class);
    }

    public function isTrusted(User $user, ?string $address): bool
    {
        return null !== $this->entryTrusting($user, $address);
    }

    /**
     * The entry that trusts an address: the address itself when it has one,
     * else a pattern, else its domain's, else null. The narrowest wins, being
     * the one the line under the message should name.
     */
    public function entryTrusting(User $user, ?string $address): ?string
    {
        return $this->findTrustedAmong($user, [AddressHelper::email($address)])[AddressHelper::email($address)] ?? null;
    }

    /**
     * entryTrusting() for a whole conversation at once — see ThreadSenderFacts.
     *
     * @param list<string> $addresses already normalised with AddressHelper::email()
     *
     * @return array<string, string> trusted address => the entry that trusts it
     */
    public function findTrustedAmong(User $user, array $addresses): array
    {
        $candidates = [];

        foreach ($addresses as $address) {
            if ('' === $address) {
                continue;
            }

            $candidates[$address] = true;
            $domain               = TrustedImageSender::domainEntryFor($address);

            if (null !== $domain) {
                $candidates[$domain] = true;
            }
        }

        if ([] === $candidates) {
            return [];
        }

        $stored = [];

        foreach ($this->findBy(['usr' => $user, 'address' => array_keys($candidates)]) as $sender) {
            $stored[$sender->address] = true;
        }

        $trusted  = [];
        $patterns = null;

        foreach ($addresses as $address) {
            if ('' === $address) {
                continue;
            }

            if (isset($stored[$address])) {
                $trusted[$address] = $address;

                continue;
            }

            $patterns ??= $this->findPatterns($user);

            foreach ($patterns as $pattern) {
                if (true === TrustedImageSender::patternMatches($pattern, $address)) {
                    $trusted[$address] = $pattern;

                    continue 2;
                }
            }

            $domain = TrustedImageSender::domainEntryFor($address);

            if (null !== $domain && isset($stored[$domain])) {
                $trusted[$address] = $domain;
            }
        }

        return $trusted;
    }

    /**
     * A user's pattern entries: a `*` somewhere, and not the `*@` of a domain.
     * The star is no wildcard to LIKE, so it is searched for as itself.
     *
     * @return list<string>
     */
    private function findPatterns(User $user): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['address'],
            $this->createQueryBuilder('trusted')
                ->select('trusted.address')
                ->andWhere('trusted.usr = :user')
                ->andWhere("trusted.address LIKE '%*%'")
                ->andWhere("trusted.address NOT LIKE '*@%'")
                ->setParameter('user', $user)
                ->getQuery()
                ->getArrayResult(),
        );
    }

    /**
     * Every entry a user has, for the settings card: domains first, since one
     * of those covers the most, then patterns, then addresses, each
     * alphabetical.
     *
     * @return list<TrustedImageSender>
     */
    public function findForUser(User $user): array
    {
        $entries = $this->findBy(['usr' => $user], ['address' => 'ASC']);
        $rank    = static fn (TrustedImageSender $entry): int => $entry->isDomain ? 0 : ($entry->isPattern ? 1 : 2);

        usort($entries, static fn (TrustedImageSender $a, TrustedImageSender $b): int => $rank($a) <=> $rank($b));

        return $entries;
    }

    /** The sender of a message, trusted. */
    public function trust(User $user, ?string $address): void
    {
        if (null === $address || false === AddressHelper::isValidEmail($address)) {
            return;
        }

        $this->add($user, $address);
    }

    /** Everyone at the sender's domain, trusted. */
    public function trustDomain(User $user, ?string $address): void
    {
        $entry = TrustedImageSender::domainEntryFor($address);

        if (null !== $entry) {
            $this->add($user, $entry);
        }
    }

    /**
     * An entry as typed into the settings card, or as built by the two above.
     * False when the input means no entry at all.
     *
     * Idempotent by construction. The unique constraint is the arbiter rather
     * than a preceding SELECT: two tabs on the same message, or a double click,
     * both reach here and one of them loses the race — which is a caught
     * exception and a no-op, not a 500 in front of someone who did nothing
     * wrong.
     */
    public function add(User $user, string $input): bool
    {
        $entry = TrustedImageSender::entryFor($input);

        if (null === $entry) {
            return false;
        }

        $entity          = new TrustedImageSender();
        $entity->usr     = $user;
        $entity->address = $entry;

        $manager = $this->getEntityManager();

        try {
            $manager->persist($entity);
            $manager->flush();
        } catch (UniqueConstraintViolationException) {
            // Already trusted. The desired state is the state we are in.
            $manager->detach($entity);
        }

        return true;
    }

    /**
     * "Stop showing" on a message: whatever trusts its sender goes, the
     * address's own entry, every pattern matching it and its domain's alike.
     * Removing only the one named would leave the images loading, which is
     * the opposite of what was asked.
     */
    public function distrust(User $user, ?string $address): void
    {
        $address = AddressHelper::email($address);

        if ('' === $address) {
            return;
        }

        $entries = array_filter([$address, TrustedImageSender::domainEntryFor($address)]);

        foreach ($this->findPatterns($user) as $pattern) {
            if (true === TrustedImageSender::patternMatches($pattern, $address)) {
                $entries[] = $pattern;
            }
        }

        foreach ($this->findBy(['usr' => $user, 'address' => array_values($entries)]) as $existing) {
            $this->getEntityManager()->remove($existing);
        }

        $this->getEntityManager()->flush();
    }

    /** One entry off the settings card. Someone else's id removes nothing. */
    public function removeEntry(User $user, int $id): void
    {
        $existing = $this->findOneBy(['usr' => $user, 'id' => $id]);

        if (null !== $existing) {
            $this->getEntityManager()->remove($existing);
            $this->getEntityManager()->flush();
        }
    }

    /** Every sender a user trusts with remote images, forgotten. */
    public function deleteForUser(User $user): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM ' . TrustedImageSender::class . ' trusted WHERE trusted.usr = :user',
        )->setParameter('user', $user)->execute();
    }
}
