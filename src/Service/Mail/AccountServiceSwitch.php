<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Account\AccountService;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\SyncTrigger;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SubscribeAccountContactsMessage;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\AddressBook\AddressBook\AddressBookRepository;
use App\Repository\Calendar\Calendar\CalendarRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\Push\PushSubscriptionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Switching one of an account's services — mail, calendar, contacts — on or
 * off, and everything that has to follow from it (#575).
 *
 * The column is the easy part. What makes this a service is what each switch
 * means beyond its own column, kept in one place so the settings page and the
 * OAuth callback (which switches services on after a wider grant) cannot do it
 * two ways:
 *
 *   - Mail off hands back the provider-side push registration for Gmail and
 *     Outlook, which only ever announces mail, and moves the primary to an
 *     account that can still send. A JMAP account keeps its stream: the server
 *     pushes calendar and contact changes down the same one.
 *   - Mail on registers push again where the account had it, seeds aliases a
 *     mailbox may have gained meanwhile, and asks for a sync straight away
 *     rather than leaving the account stale until the next poll.
 *   - Calendar or contacts on asks for a sync of each mirrored calendar or
 *     book, which is also the moment the edits made while it was off go out.
 *     Contacts on an account that never mirrored any are found first.
 *   - Off for calendar and contacts does nothing more than the column: their
 *     handlers read it, and a push channel left open expires on its own.
 *
 * Whether switching on needs a wider grant first is a separate question —
 * needsConsent() — because the answer sends the person to the provider rather
 * than changing anything here. Asking it is the caller's job; this never
 * switches a service on that the grant cannot serve without saying so.
 */
final readonly class AccountServiceSwitch
{
    public function __construct(
        private EntityManagerInterface   $em,
        private AccountRepository        $accounts,
        private AccountCreator           $accountCreator,
        private PushSubscriptionRegistry $pushRegistry,
        private AliasSeeder              $aliasSeeder,
        private CalendarRepository       $calendars,
        private AddressBookRepository    $addressBooks,
        private MessageBusInterface      $bus,
        private LoggerInterface          $logger,
    ) {
    }

    /**
     * Whether switching this service on needs the provider to grant more first.
     *
     * Only an OAuth account can be short of a permission: a password account's
     * server either serves calendars and contacts or does not, and the
     * subscribe screens say which. For OAuth, the recorded grant is compared
     * against what the service needs. A grant that was never recorded (null)
     * is the full one every account asked for before #575, and is taken at its
     * word — MailProvider::missingScopes() reads it the same way.
     */
    public function needsConsent(Account $account, AccountService $service): bool
    {
        if (AuthType::OAuth2->value !== $account->authType) {
            return false;
        }

        $provider = MailProvider::tryFrom((string) $account->oauthProvider);

        if (null === $provider) {
            return false;
        }

        $missing = $provider->missingScopes($account->oauthGrantedScopes, [$service]);

        return null !== $missing && [] !== $missing;
    }

    /**
     * Switch one service, and carry out what that means. A switch already in
     * the asked-for position does nothing, so a double submit is harmless.
     */
    public function set(Account $account, AccountService $service, bool $enabled): void
    {
        if ($enabled === $account->isEnabled($service)) {
            return;
        }

        $account->enable($service, $enabled);
        $this->em->flush();

        match ($service) {
            AccountService::Mail     => true === $enabled ? $this->mailOn($account) : $this->mailOff($account),
            AccountService::Calendar => true === $enabled ? $this->calendarOn($account) : null,
            AccountService::Contacts => true === $enabled ? $this->contactsOn($account) : null,
        };

        $this->em->flush();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function mailOff(Account $account): void
    {
        // Gmail's watch and Graph's subscription announce mail and nothing
        // else, so with mail off they are only traffic to ignore. Handed back
        // rather than left to lapse, so the provider stops calling. pushEnabled
        // is left as it was — it is the person's choice for when mail is on.
        if (false === $account->isJmap()) {
            $this->unsubscribePush($account);
        }

        $this->accountCreator->ensurePrimary($this->accounts->findForUserOrdered($account->usr));
    }

    private function mailOn(Account $account): void
    {
        if (true === $account->pushEnabled && false === $account->isJmap()) {
            $manager = $this->pushRegistry->resolve($account);

            if (null !== $manager && false === $manager->subscribe($account)) {
                // Polling covers it, as it does when push fails at connect.
                $this->logger->info('AccountServiceSwitch: push could not be registered on switching mail on', [
                    'accountId' => $account->id,
                ]);
            }
        }

        $this->accountCreator->ensurePrimary($this->accounts->findForUserOrdered($account->usr));
        $this->em->flush();

        // Idempotent; a mailbox can gain a send-as address while mail is off.
        $this->aliasSeeder->seed($account);

        $this->bus->dispatch(new SyncAccountMessage((int) $account->id, SyncTrigger::Manual));
    }

    private function calendarOn(Account $account): void
    {
        foreach ($this->calendars->findMirroredForAccount($account) as $calendar) {
            $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));
        }
    }

    private function contactsOn(Account $account): void
    {
        $books = $this->addressBooks->findForHolder($account);

        // Never mirrored — contacts were off when it was connected, or the
        // grant predates them. Finding the books is the handler's job, and
        // queued: it is a call to the provider, and this is a click.
        if ([] === $books) {
            $this->bus->dispatch(new SubscribeAccountContactsMessage((int) $account->id));

            return;
        }

        foreach ($books as $book) {
            $this->bus->dispatch(new SyncAddressBookMessage((int) $book->id));
        }
    }

    private function unsubscribePush(Account $account): void
    {
        $manager = $this->pushRegistry->resolve($account);

        if (null === $manager) {
            return;
        }

        try {
            $manager->unsubscribe($account);
        } catch (Throwable $e) {
            // Best effort, as on account deletion: a registration the provider
            // keeps calling about meets a mail-off account and is ignored.
            $this->logger->warning('AccountServiceSwitch: could not hand back push on switching mail off', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
