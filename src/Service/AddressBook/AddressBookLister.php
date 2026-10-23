<?php

declare(strict_types=1);

namespace App\Service\AddressBook;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\AccountRepository;

/**
 * What Settings → Contacts draws: the contacts connections, the Google and
 * Microsoft accounts, and the books mirrored through each.
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
        private AccountRepository     $accounts,
    ) {
    }

    /**
     * The connections that carry address books, the Google and Microsoft
     * accounts (whose books ride the mail grant), and the books of each.
     * `contactAccountsGranted` says, per account id, whether its grant
     * reaches contacts — false is an account connected before plMail asked,
     * which one reconnect fixes; null is a grant that never said.
     *
     * @return array{
     *     contactConnections: list<Integration>,
     *     addressBooks: array<int, list<AddressBook>>,
     *     contactAccounts: list<Account>,
     *     accountBooks: array<int, list<AddressBook>>,
     *     contactAccountsGranted: array<int, bool|null>,
     * }
     */
    public function sectionFor(User $user): array
    {
        $connections = array_values(array_filter(
            $this->integrations->findForUserOrdered($user),
            // offersAddressBooks() rather than the Contacts kind, so the
            // Nextcloud connection made for files is listed too (#158).
            static fn (Integration $integration): bool => $integration->provider->offersAddressBooks(),
        ));

        $accounts = array_values(array_filter(
            $this->accounts->findForUserOrdered($user),
            static fn (Account $account): bool => true === $account->isGmail() || true === $account->isMicrosoft(),
        ));

        $byConnection = [];
        $byAccount    = [];

        foreach ($this->books->findForUser($user) as $book) {
            if (null !== $book->account) {
                $byAccount[(int) $book->account->id][] = $book;
            } elseif (null !== $book->integration) {
                $byConnection[(int) $book->integration->id][] = $book;
            }
        }

        $granted = [];

        foreach ($accounts as $account) {
            $granted[(int) $account->id] = MailProvider::tryFrom((string) $account->oauthProvider)?->grantsContactsAccess($account->oauthGrantedScopes);
        }

        return [
            'contactConnections'     => $connections,
            'addressBooks'           => $byConnection,
            'contactAccounts'        => $accounts,
            'accountBooks'           => $byAccount,
            'contactAccountsGranted' => $granted,
        ];
    }
}
