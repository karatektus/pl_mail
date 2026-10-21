<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook;

use App\Domain\DTO\AddressBook\AddressBookChangeSet;
use App\Domain\DTO\AddressBook\AddressBookSource;
use App\Domain\DTO\AddressBook\RemoteCard;
use App\Domain\DTO\AddressBook\RemoteContactWrite;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Interface\AddressBookDriverInterface;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Repository\Contacts\ContactRepository;
use App\Service\AddressBook\AddressBookDriverRegistry;
use App\Service\AddressBook\AddressBookLock;
use App\Service\AddressBook\AddressBookSync;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\Contacts\ParsedContact;
use App\Service\Contacts\VCardMapper;
use App\Tests\Service\AddressBook\CardDav\CardDavFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The photo through a sync, against a driver that serves photos apart from
 * the contact (as Google's and Outlook's do).
 *
 * The claims: a photo is fetched only when its version changed; a server
 * that says there is none removes the one here; a fetch that fails keeps the
 * one shown; a push that sent a photo clears the mark that asked for it.
 */
final class AddressBookSyncPhotoTest extends TestCase
{
    /** @var list<Contact> */
    private array $cards = [];

    private AddressBook $book;

    private PhotoDriver $driver;

    private string $root;

    protected function setUp(): void
    {
        $this->cards  = [];
        $this->book   = CardDavFixture::book();
        $this->driver = new PhotoDriver();
        $this->root   = sys_get_temp_dir() . '/plmail-photo-sync-' . bin2hex(random_bytes(4));

        new ReflectionProperty(User::class, 'id')->setValue($this->book->usr, 7);
    }

    public function testAPhotoIsFetchedOnceAndAgainOnlyWhenItsVersionChanges(): void
    {
        $this->driver->photo = new ContactPhoto($this->png(), 'image/png');

        $this->pull('v1');

        self::assertSame(1, $this->driver->photoFetches);
        self::assertNotNull($this->cards[0]->photoPath);
        self::assertSame('v1', $this->cards[0]->photoVersion);

        $first = $this->cards[0]->photoPath;

        $this->pull('v1', etag: '"2"');

        self::assertSame(1, $this->driver->photoFetches, 'the contact changed, its photo did not');
        self::assertSame($first, $this->cards[0]->photoPath);

        $this->pull('v2', etag: '"3"');

        self::assertSame(2, $this->driver->photoFetches);
        self::assertNotSame($first, $this->cards[0]->photoPath, 'a new photo replaces the stored one');
        self::assertFileDoesNotExist($this->root . '/var/uploads/' . $first, 'and the old file goes');
    }

    public function testAServerThatSaysThereIsNoneRemovesTheOneHere(): void
    {
        $this->driver->photo = new ContactPhoto($this->png(), 'image/png');
        $this->pull('v1');

        $this->pull(null, etag: '"2"', absent: true);

        self::assertNull($this->cards[0]->photoPath);
        self::assertNull($this->cards[0]->photoVersion);
    }

    public function testAFetchThatFailsKeepsThePhotoShown(): void
    {
        $this->driver->photo = new ContactPhoto($this->png(), 'image/png');
        $this->pull('v1');
        $kept = $this->cards[0]->photoPath;

        $this->driver->photoFails = true;
        $this->pull('v2', etag: '"2"');

        self::assertSame($kept, $this->cards[0]->photoPath);
        self::assertSame('v1', $this->cards[0]->photoVersion, 'so the next change tries again');
    }

    public function testAPushThatSentThePhotoClearsTheMark(): void
    {
        $card             = Contact::create($this->book->usr, 'Ada');
        $card->addressBook = $this->book;
        $card->href        = 'c1';
        $card->syncState   = SyncState::PendingUpdate;
        $card->photoDirty  = true;
        $this->cards[]     = $card;

        $this->service()->pushPending($this->book);

        self::assertTrue($this->driver->sawDirtyPhoto, 'the driver was asked to send it');
        self::assertFalse($card->photoDirty);
        self::assertSame(SyncState::Clean, $card->syncState);
    }

    private function pull(?string $version, string $etag = '"1"', bool $absent = false): void
    {
        $parsed                = new ParsedContact();
        $parsed->uid           = 'c1';
        $parsed->displayName   = 'Ada';
        $parsed->photoDeferred = null !== $version;
        $parsed->photoVersion  = $version;
        $parsed->photoAbsent   = $absent;

        $this->driver->changes = AddressBookChangeSet::of([RemoteCard::parsed('c1', $etag, $parsed)], 'token', false);

        $this->service()->sync($this->book);
    }

    private function service(): AddressBookSync
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Contact) {
                $this->cards[] = $entity;
            }
        });

        $cards = &$this->cards;

        $repository = new class($cards) extends ContactRepository {
            /** @param list<Contact> $cards */
            public function __construct(private array &$cards)
            {
            }

            public function findPending(AddressBook $book): array
            {
                return array_values(array_filter($this->cards, static fn (Contact $c): bool => $c->syncState->isPending()));
            }

            public function indexForBook(AddressBook $book): array
            {
                $byHref = [];
                $byUid  = [];

                foreach ($this->cards as $card) {
                    $byHref[(string) $card->href] = $card;
                    $byUid[$card->uid]            = $card;
                }

                return ['byHref' => $byHref, 'byUid' => $byUid];
            }
        };

        return new AddressBookSync(
            new AddressBookDriverRegistry([$this->driver]),
            new AddressBookLock($this->createStub(Connection::class)),
            new VCardMapper(),
            $repository,
            new ContactPhotoStore($this->root),
            new ContactSyncTracker($this->createStub(MessageBusInterface::class)),
            $em,
            new NullLogger(),
        );
    }

    /** A real image: the photo store re-encodes with GD. */
    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

/** A driver whose photos come separately, scripted. */
final class PhotoDriver implements AddressBookDriverInterface
{
    public AddressBookChangeSet $changes;

    public ?ContactPhoto $photo = null;

    public bool $photoFails = false;

    public int $photoFetches = 0;

    public bool $sawDirtyPhoto = false;

    public function handles(AddressBookSource $source): bool
    {
        return true;
    }

    public function discover(AddressBookSource $source): array
    {
        return [];
    }

    public function pull(AddressBook $book, ?string $syncToken): AddressBookChangeSet
    {
        return $this->changes;
    }

    public function put(AddressBook $book, Contact $contact): RemoteContactWrite
    {
        $this->sawDirtyPhoto = $contact->photoDirty;

        return new RemoteContactWrite((string) $contact->href, '"pushed"');
    }

    public function photo(AddressBook $book, RemoteCard $card): ?ContactPhoto
    {
        ++$this->photoFetches;

        if (true === $this->photoFails) {
            throw new CalendarSyncException('photo host down');
        }

        return $this->photo;
    }

    public function delete(AddressBook $book, ?string $href, ?string $etag): void
    {
    }
}
