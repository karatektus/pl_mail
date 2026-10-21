<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Helper\AddressHelper;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets a template ask "is this address in the signed-in user's address book?".
 *
 * The answer is remembered for the request: a thread shows the same few senders
 * many times, and one lookup per distinct address is plenty.
 */
final class AddressBookExtension extends AbstractExtension
{
    /** @var array<string, int|null> */
    private array $memo = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('address_book_contact_id', $this->contactId(...)),
            new TwigFunction('address_book_key', self::key(...)),
        ];
    }

    /** The id of the (not trashed) contact that owns this address, or null. */
    public function contactId(?string $address): ?int
    {
        $email = AddressHelper::email($address);
        $user  = $this->security->getUser();

        if (false === AddressHelper::isValidEmail($email) || false === $user instanceof User) {
            return null;
        }

        $cacheKey = $user->id . '|' . $email;

        if (array_key_exists($cacheKey, $this->memo)) {
            return $this->memo[$cacheKey];
        }

        $id = $this->em->createQuery(
            'SELECT c.id FROM App\Entity\Contacts\ContactEmail e
             JOIN e.contact c
             WHERE c.usr = :user AND c.trashedAt IS NULL AND LOWER(e.email) = :email'
        )
            ->setParameter('user', $user)
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);

        return $this->memo[$cacheKey] = null === $id ? null : (int) $id;
    }

    /**
     * A selector-safe handle for an address, so a turbo-stream can find every
     * badge for one sender without putting the address itself in a selector.
     */
    public static function key(?string $address): string
    {
        return substr(sha1(AddressHelper::email($address)), 0, 16);
    }
}
