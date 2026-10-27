<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\Enum\Mail\SyncTrigger;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Calendar\CalendarRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * What a JMAP StateChange means for plMail: which syncs to queue.
 *
 * One answer for both ways a server can push (RFC 8620 §7) — the EventSource
 * stream app:jmap:listen holds open, and the PushSubscription callback the
 * webhook receives — so the two cannot come to disagree about what a change
 * to `CalendarEvent` should wake. A StateChange names data types and their new
 * states, never objects, so what it wakes is the ordinary sync of that kind:
 * the account's mail sync for mail types, each mirrored calendar's pull for
 * calendar types, each address book's for contacts. Those syncs read the
 * changes from the states they hold, which is what makes a push safe to
 * repeat, to lose, or to arrive for a change already read.
 *
 * A StateChange for another account on the same server — a shared mailbox the
 * login can see — is ignored: plMail syncs the primary account only.
 */
final readonly class JmapPushFanout
{
    /** Types whose change the mail sync reads. */
    private const array MAIL_TYPES = ['Email', 'Mailbox', 'Thread', 'EmailDelivery'];

    /** Types whose change a calendar pull reads. */
    private const array CALENDAR_TYPES = ['CalendarEvent', 'Calendar'];

    /** Types whose change an address-book pull reads. */
    private const array CONTACT_TYPES = ['ContactCard', 'AddressBook'];

    public function __construct(
        private MessageBusInterface   $bus,
        private CalendarRepository    $calendars,
        private AddressBookRepository $addressBooks,
    ) {
    }

    /**
     * Queue what a StateChange asks for, and say how many syncs that was.
     *
     * @param array<string, mixed> $stateChange the decoded StateChange object
     * @param list<string>         $accountIds  the JMAP account ids that are
     *                                          this plMail account's: its mail,
     *                                          calendar and contacts accounts
     */
    public function dispatch(Account $account, array $stateChange, array $accountIds): int
    {
        $changed = is_array($stateChange['changed'] ?? null) ? $stateChange['changed'] : [];
        $types   = [];

        foreach ($accountIds as $accountId) {
            foreach (is_array($changed[$accountId] ?? null) ? array_keys($changed[$accountId]) : [] as $type) {
                $types[(string) $type] = true;
            }
        }

        $queued = 0;

        if ([] !== array_intersect(array_keys($types), self::MAIL_TYPES)) {
            $this->bus->dispatch(new SyncAccountMessage((int) $account->id, SyncTrigger::Push));
            ++$queued;
        }

        if ([] !== array_intersect(array_keys($types), self::CALENDAR_TYPES)) {
            foreach ($this->calendars->findMirroredForAccount($account) as $calendar) {
                $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));
                ++$queued;
            }
        }

        if ([] !== array_intersect(array_keys($types), self::CONTACT_TYPES)) {
            foreach ($this->addressBooks->findForHolder($account) as $book) {
                $this->bus->dispatch(new SyncAddressBookMessage((int) $book->id));
                ++$queued;
            }
        }

        return $queued;
    }
}
