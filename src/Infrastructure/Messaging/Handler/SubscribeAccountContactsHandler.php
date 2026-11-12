<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Exception\CalendarSyncPermanentException;
use App\Infrastructure\Messaging\Message\SubscribeAccountContactsMessage;
use App\Repository\AddressBook\AddressBook\AddressBookRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\AddressBook\AddressBookSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Subscribes every address book a Google or Microsoft account offers — the
 * one Google has, each Outlook contact folder — and lets their first sync run.
 *
 * Only for an account with none mirrored yet. One that has books has had
 * this already, and the user may since have unticked some in Settings →
 * Contacts; subscribing again on every reconnect would bring back what they
 * took away.
 *
 * A transient failure escapes, for the transport's retries. A permanent one
 * (the scope withdrawn between the grant and this) is logged and dropped: there is no book to record it on, and Settings →
 * Contacts offers the account's books by hand.
 */
#[AsMessageHandler]
final readonly class SubscribeAccountContactsHandler
{
    public function __construct(
        private AccountRepository     $accounts,
        private AddressBookRepository $books,
        private AddressBookSubscriber $subscriber,
        private LoggerInterface       $logger,
    ) {
    }

    public function __invoke(SubscribeAccountContactsMessage $message): void
    {
        $account = $this->accounts->find($message->accountId);

        // Contacts switched off at connect time (#575) is a "no" to mirroring
        // them, not a "later": switching contacts on afterwards queues this
        // again, which is when the books are found.
        if (null === $account || null === $account->usr || false === $account->contactsEnabled || [] !== $this->books->findForHolder($account)) {
            return;
        }

        try {
            $offers = $this->subscriber->discover($account);
            $result = $this->subscriber->apply($account, array_map(static fn (array $offer): string => $offer['remote']->remoteId, $offers));
        } catch (CalendarSyncPermanentException $e) {
            $this->logger->notice('AddressBookSync: could not list this account\'s address books', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return;
        }

        if (0 < $result['subscribed']) {
            $this->logger->info('AddressBookSync: mirrored an account\'s address books', [
                'accountId' => $account->id,
                'books'     => $result['subscribed'],
            ]);
        }
    }
}
