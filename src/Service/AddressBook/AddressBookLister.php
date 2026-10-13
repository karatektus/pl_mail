<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\Enum\Integration\ServiceKind;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Integration\IntegrationRepository;

/**
 * What Settings → Contacts draws: the contacts connections, and the books
 * mirrored through each.
 *
 * One place, used by the first render (SettingsController) and by every stream
 * after a change (ContactSettingsController) — CalendarSourceLister's reason
 * for existing, applied to contacts: two spellings of "which connections are
 * contacts ones" is how a row appears on reload and not after a click.
 */
final readonly class AddressBookLister
{
    public function __construct(
        private IntegrationRepository $integrations,
        private AddressBookRepository $books,
    ) {
    }

    /**
     * @return array{contactConnections: list<Integration>, addressBooks: array<int, list<AddressBook>>}
     */
    public function sectionFor(User $user): array
    {
        $connections = array_values(array_filter(
            $this->integrations->findForUserOrdered($user),
            static fn (Integration $integration): bool => ServiceKind::Contacts === $integration->provider->kind(),
        ));

        $byConnection = [];

        foreach ($this->books->findForUser($user) as $book) {
            $byConnection[(int) $book->integration->id][] = $book;
        }

        return ['contactConnections' => $connections, 'addressBooks' => $byConnection];
    }
}
