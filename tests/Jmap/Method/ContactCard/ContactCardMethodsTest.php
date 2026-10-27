<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\ContactCard;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Jmap\Contact\ContactCards;
use App\Jmap\Method\ContactCard\AddressBookGetMethod;
use App\Jmap\Method\ContactCard\ContactCardChangesMethod;
use App\Jmap\Method\ContactCard\ContactCardGetMethod;
use App\Jmap\Method\ContactCard\ContactCardQueryMethod;
use App\Jmap\Method\ContactCard\ContactCardSetMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Jmap\Session\SessionBuilder;
use App\Service\Contacts\ContactManager;
use App\Tests\Jmap\JmapTestCase;
use App\Tests\Support\Contacts\ContactsKernelFixture;

/**
 * RFC 9610 on plMail's contacts: address books, cards, and what changed.
 *
 * What another JMAP client relies on — plMail's own JmapContactsDriver first,
 * syncing one plMail from another: a card written comes back as the contact it
 * was, a patch replaces only what it names, a binned contact is gone, and every
 * change, however it was made, is in ContactCard/changes.
 */
final class ContactCardMethodsTest extends JmapTestCase
{
    private const string PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private ContactsKernelFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new ContactsKernelFixture($this->em, self::getContainer()->get(ContactSyncTracker::class));
    }

    public function testTheSessionServesContactsUnderTheRfcFromOneAccount(): void
    {
        $session = self::getContainer()->get(SessionBuilder::class)->build($this->user);

        self::assertArrayHasKey(Capability::IETF_CONTACTS, $session['capabilities']);
        self::assertSame($this->accountId(), $session['primaryAccounts'][Capability::IETF_CONTACTS]);
        self::assertArrayHasKey(Capability::IETF_CONTACTS, $session['accounts'][$this->accountId()]['accountCapabilities']);
    }

    public function testPlmailsOwnBookComesFirstAndAMirroredOneSaysWhetherItTakesWrites(): void
    {
        $shared = $this->fixture->book($this->user, 'Shared', readOnly: true);

        $books = array_column($this->handle(AddressBookGetMethod::class, [])['list'], null, 'id');

        self::assertTrue($books[ContactCards::LOCAL_BOOK]['isDefault']);
        self::assertStringContainsString((string) $this->user->email, $books[ContactCards::LOCAL_BOOK]['name']);
        self::assertFalse($books[(string) $shared->id]['myRights']['mayWrite']);
    }

    public function testACardWrittenComesBackAsTheContactItWas(): void
    {
        $id   = $this->create();
        $card = $this->card($id);

        self::assertSame([ContactCards::LOCAL_BOOK => true], $card['addressBookIds']);
        self::assertSame('Dora Bee', $card['name']['full']);
        self::assertSame('dora@example.test', array_values($card['emails'])[0]['address']);
        self::assertTrue(array_values($card['phones'])[0]['features']['mobile']);
        self::assertSame('Bee GmbH', $card['organizations']['o']['name']);
        // As a data: URI, and a PNG still — not byte for byte, because the
        // photo store re-encodes what it is given.
        self::assertStringStartsWith('data:image/png;base64,', $card['media']['photo']['uri']);
        self::assertStringStartsWith("\x89PNG", (string) base64_decode(substr($card['media']['photo']['uri'], strlen('data:image/png;base64,')), true));
    }

    public function testAPatchReplacesOnlyWhatItNames(): void
    {
        $id = $this->create();

        $result = $this->handle(ContactCardSetMethod::class, ['update' => [$id => ['organizations' => ['o' => ['name' => 'Bee AG']]]]]);

        self::assertSame([$id => null], $result['updated']);

        $card = $this->card($id);

        self::assertSame('Bee AG', $card['organizations']['o']['name']);
        self::assertSame('dora@example.test', array_values($card['emails'])[0]['address'], 'the emails were not in the patch');
        self::assertArrayHasKey('media', $card, 'nor was the photo');
    }

    public function testADestroyedCardIsBinnedAndGone(): void
    {
        $id    = $this->create();
        $state = $this->state();

        $this->handle(ContactCardSetMethod::class, ['destroy' => [$id]]);

        self::assertNotNull($this->em->find(Contact::class, (int) $id)?->trashedAt, 'binned, as the contact page does');
        self::assertSame([$id], $this->handle(ContactCardGetMethod::class, ['ids' => [$id]])['notFound']);
        self::assertSame([$id], $this->changes($state)['destroyed']);
    }

    /**
     * Every change is in the log however it was made — here the contact
     * page's way, through the manager and the child rows — because the log is
     * written from the flush, not by the writers.
     */
    public function testAChangeMadeOutsideJmapIsInTheChanges(): void
    {
        $id      = $this->create();
        $contact = $this->em->find(Contact::class, (int) $id) ?? self::fail('created');
        $state   = $this->state();

        ContactEmail::create($contact, 'second@example.test');
        $this->em->flush();

        self::assertSame([$id], $this->changes($state)['updated'], 'an email added is the card changing');

        $state   = $this->state();
        $manager = self::getContainer()->get(ContactManager::class);
        $manager->softDelete($contact);
        $manager->restore($contact);

        $changes = $this->changes($state);

        self::assertSame([$id], $changes['created'], 'restored from the bin, it is new to a client');
        self::assertSame([], $changes['destroyed']);
    }

    public function testCreatedThenEditedInOneWindowIsCreated(): void
    {
        $state = $this->state();
        $id    = $this->create();

        $this->handle(ContactCardSetMethod::class, ['update' => [$id => ['notes' => ['n' => ['note' => 'later']]]]]);

        $changes = $this->changes($state);

        self::assertSame([$id], $changes['created']);
        self::assertSame([], $changes['updated']);
    }

    public function testAQueryNamesTheCardsInOneBook(): void
    {
        $own    = $this->create();
        $book   = $this->fixture->book($this->user);
        $synced = (string) $this->fixture->contact($this->user, 'Synced Sam', ['sam@example.test'], $book)->id;

        self::assertSame([$own], $this->query(ContactCards::LOCAL_BOOK));
        self::assertSame([$synced], $this->query((string) $book->id));

        $this->expectException(MethodException::class);
        $this->handle(ContactCardQueryMethod::class, ['filter' => ['text' => 'Sam']]);
    }

    public function testACardInAReadOnlyBookIsNotWrittenAndNoCardMovesBooks(): void
    {
        $shared   = $this->fixture->book($this->user, 'Shared', readOnly: true);
        $readOnly = (string) $this->fixture->contact($this->user, 'Read Only', [], $shared)->id;
        $own      = $this->create();

        $result = $this->handle(ContactCardSetMethod::class, [
            'create' => ['x' => ['@type' => 'Card', 'addressBookIds' => [(string) $shared->id => true], 'name' => ['full' => 'Nope']]],
            'update' => [
                $readOnly => ['notes' => ['n' => ['note' => 'no']]],
                $own      => ['addressBookIds' => [(string) $shared->id => true]],
            ],
        ]);

        self::assertSame('forbidden', $result['notCreated']['x']['type']);
        self::assertSame('forbidden', $result['notUpdated'][$readOnly]['type']);
        self::assertSame(['addressBookIds'], $result['notUpdated'][$own]['properties']);
    }

    public function testOnlyTheOneAccountServesContacts(): void
    {
        $other = $this->secondAccount();

        $this->expectException(MethodException::class);
        self::getContainer()->get(ContactCardGetMethod::class)->handle(['accountId' => (string) $other->id, 'ids' => []], $this->context());
    }

    public function testAStateChangeNamesTheContactTypes(): void
    {
        $snapshot = self::getContainer()->get(StateChangeBuilder::class)->snapshot($this->user);

        self::assertArrayHasKey('ContactCard', $snapshot[$this->accountId()]);
        self::assertArrayHasKey('AddressBook', $snapshot[$this->accountId()]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function create(): string
    {
        $result = $this->handle(ContactCardSetMethod::class, ['create' => ['k' => [
            '@type'          => 'Card',
            'version'        => '1.0',
            'addressBookIds' => [ContactCards::LOCAL_BOOK => true],
            'name'           => ['components' => [['kind' => 'given', 'value' => 'Dora'], ['kind' => 'surname', 'value' => 'Bee']], 'full' => 'Dora Bee'],
            'emails'         => ['e' => ['address' => 'dora@example.test', 'contexts' => ['work' => true]]],
            'phones'         => ['p' => ['number' => '+49 89 1', 'features' => ['mobile' => true]]],
            'organizations'  => ['o' => ['name' => 'Bee GmbH']],
            'media'          => ['m' => ['kind' => 'photo', 'uri' => self::PHOTO, 'mediaType' => 'image/png']],
        ]]]);

        return $result['created']['k']['id'] ?? self::fail('not created: ' . json_encode($result['notCreated']));
    }

    /** @return array<string, mixed> */
    private function card(string $id): array
    {
        $this->em->clear();

        return $this->handle(ContactCardGetMethod::class, ['ids' => [$id]])['list'][0] ?? self::fail('no card ' . $id);
    }

    private function state(): string
    {
        return $this->handle(ContactCardGetMethod::class, ['ids' => []])['state'];
    }

    /** @return array<string, mixed> */
    private function changes(string $since): array
    {
        return $this->handle(ContactCardChangesMethod::class, ['sinceState' => $since]);
    }

    /** @return list<string> */
    private function query(string $book): array
    {
        return $this->handle(ContactCardQueryMethod::class, ['filter' => ['inAddressBook' => $book]])['ids'];
    }

    /**
     * @param class-string $method
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function handle(string $method, array $arguments): array
    {
        return self::getContainer()->get($method)->handle($arguments + ['accountId' => $this->accountId()], $this->context());
    }
}
