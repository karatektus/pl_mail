<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\DTO\AddressBook\RemoteAddressBook;
use App\Domain\DTO\AddressBook\RemoteCard;
use App\Domain\DTO\AddressBook\RemoteContactWrite;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\AddressBookDriverInterface;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SubscribeAccountContactsHandler;
use App\Infrastructure\Messaging\Message\SubscribeAccountContactsMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Mail\AccountRepository;
use App\Service\AddressBook\AddressBookDriverRegistry;
use App\Service\AddressBook\AddressBookSubscriber;
use App\Service\Contacts\ContactPhoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A Google or Microsoft account's address books appear without anybody asking,
 * once — and never come back after the user unticked them.
 */
final class SubscribeAccountContactsHandlerTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testEveryBookTheAccountOffersIsMirrored(): void
    {
        $account = $this->account();

        $this->handler($account, [], [new RemoteAddressBook('F-default', 'Contacts', false), new RemoteAddressBook('F-work', 'Work', false)])
            (new SubscribeAccountContactsMessage(7));

        self::assertSame(['F-default', 'F-work'], array_map(static fn (AddressBook $b): string => $b->remoteId, $this->persisted));
        self::assertSame($account, $this->persisted[0]->account, 'on the account, not a connection');
    }

    public function testAnAccountThatAlreadyHasBooksIsLeftAlone(): void
    {
        $account = $this->account();
        $kept    = new AddressBook($account->usr ?? new User(), $account, 'F-default', 'Contacts');

        $this->handler($account, [$kept], [new RemoteAddressBook('F-default', 'Contacts', false), new RemoteAddressBook('F-work', 'Work', false)])
            (new SubscribeAccountContactsMessage(7));

        self::assertSame([], $this->persisted, 'F-work was unticked once; it stays unticked');
    }

    public function testAGrantWithoutTheScopeIsDroppedQuietly(): void
    {
        $account = $this->account();

        $this->handler($account, [], new CalendarSyncPermanentException('no scope'))(new SubscribeAccountContactsMessage(7));

        self::assertSame([], $this->persisted);
    }

    /**
     * @param list<AddressBook>                                     $existing
     * @param list<RemoteAddressBook>|CalendarSyncPermanentException $offered
     */
    private function handler(Account $account, array $existing, array|CalendarSyncPermanentException $offered): SubscribeAccountContactsHandler
    {
        $this->persisted = [];

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('find')->willReturn($account);

        $books = $this->createStub(AddressBookRepository::class);
        $books->method('findForHolder')->willReturn($existing);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $driver = new class($offered) implements AddressBookDriverInterface {
            /** @param list<RemoteAddressBook>|CalendarSyncPermanentException $offered */
            public function __construct(private array|CalendarSyncPermanentException $offered)
            {
            }

            public function handles(AddressBookSource $source): bool
            {
                return null !== $source->account;
            }

            public function discover(AddressBookSource $source): array
            {
                return $this->offered instanceof CalendarSyncPermanentException ? throw $this->offered : $this->offered;
            }

            public function pull(AddressBook $book, ?string $syncToken): AddressBookChangeSet
            {
                return AddressBookChangeSet::of([], null, true);
            }

            public function put(AddressBook $book, Contact $contact): RemoteContactWrite
            {
                return new RemoteContactWrite('x', null);
            }

            public function photo(AddressBook $book, RemoteCard $card): ?ContactPhoto
            {
                return null;
            }

            public function delete(AddressBook $book, ?string $href, ?string $etag): void
            {
            }
        };

        return new SubscribeAccountContactsHandler(
            $accounts,
            $books,
            new AddressBookSubscriber(new AddressBookDriverRegistry([$driver]), $books, $em, $bus),
            new NullLogger(),
        );
    }

    private function account(): Account
    {
        $account                = new Account();
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Microsoft->value;
        $account->usr           = new User();

        return $account;
    }
}
