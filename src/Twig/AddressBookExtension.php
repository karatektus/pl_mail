<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Helper\AddressHelper;
use App\Entity\User\User;
use App\Repository\Contacts\ContactEmail\ContactEmailRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets a template ask "is this address in the signed-in user's address book?".
 *
 * The answer is remembered for the request: a thread shows the same few senders
 * many times, and one lookup per distinct address is plenty. Only for the
 * request: in worker mode the service outlives it, and an answer kept from
 * the last request would show "Add to contacts" on an address the person had
 * just added (and let ContactFromMailController add it again).
 */
final class AddressBookExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, int|null> */
    private array $memo = [];

    public function reset(): void
    {
        $this->memo = [];
    }

    public function __construct(
        private readonly ContactEmailRepository $contactEmailRepository,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('address_book_contact_id', $this->contactId(...)),
            new TwigFunction('address_book_prime', $this->prime(...)),
            new TwigFunction('address_book_key', self::key(...)),
        ];
    }

    /**
     * Looks up many addresses in one query, so contactId() answers them from
     * the memo. A thread shows a badge on every sender and every address in
     * every message's details; one query each made a long conversation cost a
     * query per new address (ThreadViewQueryBudgetTest).
     *
     * @param iterable<?string> $addresses
     */
    public function prime(iterable $addresses): void
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return;
        }

        $wanted = [];
        foreach ($addresses as $address) {
            $email = AddressHelper::email($address);

            if (true === AddressHelper::isValidEmail($email) && false === array_key_exists($user->id . '|' . $email, $this->memo)) {
                $wanted[$email] = true;
            }
        }

        if ([] === $wanted) {
            return;
        }

        $ids = $this->contactEmailRepository->contactIdsByEmail($user, array_keys($wanted));

        foreach (array_keys($wanted) as $email) {
            $this->memo[$user->id . '|' . $email] = $ids[$email] ?? null;
        }
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

        $id = $this->contactEmailRepository->contactIdByEmail($user, $email);

        return $this->memo[$cacheKey] = $id;
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
