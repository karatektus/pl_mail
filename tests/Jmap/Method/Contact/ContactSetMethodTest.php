<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Contact;

use App\Domain\Enum\Calendar\SyncState;
use App\Infrastructure\Event\Listener\ContactSyncTracker;
use App\Jmap\Method\Contact\ContactGetMethod;
use App\Jmap\Method\Contact\ContactSetMethod;
use App\Tests\Jmap\JmapTestCase;
use App\Tests\Support\Contacts\ContactsKernelFixture;

/**
 * Contact/set and Contact/get over contacts that live in synced address books.
 *
 * What a phone must be able to rely on: a contact from a read-only book says
 * so, and an update or destroy of it is refused rather than accepted and then
 * quietly undone by the next pull; one from a writable book is changed and
 * queued for its server like an edit on the web.
 */
final class ContactSetMethodTest extends JmapTestCase
{
    private ContactSetMethod $set;
    private ContactGetMethod $get;
    private ContactsKernelFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->set     = self::getContainer()->get(ContactSetMethod::class);
        $this->get     = self::getContainer()->get(ContactGetMethod::class);
        $this->fixture = new ContactsKernelFixture($this->em, self::getContainer()->get(ContactSyncTracker::class));
    }

    public function testGetSaysWhereAContactLivesAndWhetherItCanBeChanged(): void
    {
        $book    = $this->fixture->book($this->user, 'Company directory', readOnly: true);
        $synced  = $this->fixture->contact($this->user, 'The CEO', book: $book);
        $local   = $this->fixture->contact($this->user, 'A Friend');

        $list = $this->get->handle(['accountId' => $this->accountId(), 'ids' => [(string) $synced->id, (string) $local->id]], $this->context())['list'];

        self::assertSame((string) $book->id, $list[0]['addressBookId']);
        self::assertSame('Company directory', $list[0]['addressBookName']);
        self::assertTrue($list[0]['isReadOnly']);
        self::assertNull($list[1]['addressBookId']);
        self::assertFalse($list[1]['isReadOnly']);
    }

    public function testAContactInAReadOnlyBookIsNotUpdatedOrDestroyed(): void
    {
        $book    = $this->fixture->book($this->user, 'Company directory', readOnly: true);
        $contact = $this->fixture->contact($this->user, 'The CEO', book: $book);
        $id      = (string) $contact->id;

        $result = $this->call(['update' => [$id => ['displayName' => 'Renamed']], 'destroy' => [$id]]);

        self::assertSame('forbidden', $result['notUpdated'][$id]['type']);
        self::assertSame('forbidden', $result['notDestroyed'][$id]['type']);

        $this->em->refresh($contact);
        self::assertSame('The CEO', $contact->displayName);
        self::assertFalse($contact->isTrashed());
    }

    public function testEveryFieldIsWrittenAndReadBack(): void
    {
        $result = $this->call(['create' => ['c1' => [
            'displayName'       => 'Haru Tanaka',
            'namePrefix'        => 'Dr.',
            'middleName'        => 'K.',
            'phoneticGivenName' => 'ハル',
            'department'        => 'Research',
            'officeLocation'    => 'Room 14',
            'birthday'          => '--05-12',
            'dates'             => [['label' => 'anniversary', 'date' => '2010-06-01']],
            'relations'         => [['label' => 'Godmother', 'name' => 'Mary'], ['label' => 'spouse', 'name' => '  ']],
            'urls'              => [['label' => 'blog', 'url' => 'https://blog.example']],
            'ims'               => [['service' => 'sip', 'handle' => 'haru@sip.example', 'label' => 'work']],
            'customFields'      => [['label' => 'Employee ID', 'value' => '4711']],
        ]]]);

        $id      = $result['created']['c1']['id'] ?? self::fail('not created: ' . json_encode($result['notCreated'] ?? null));
        $contact = $this->get->handle(['accountId' => $this->accountId(), 'ids' => [$id]], $this->context())['list'][0];

        self::assertSame('Dr.', $contact['namePrefix']);
        self::assertSame('K.', $contact['middleName']);
        self::assertSame('ハル', $contact['phoneticGivenName']);
        self::assertSame('Research', $contact['department']);
        self::assertSame('Room 14', $contact['officeLocation']);
        self::assertSame('--05-12', $contact['birthday']);
        self::assertSame([['label' => 'anniversary', 'date' => '2010-06-01']], $contact['dates']);
        self::assertSame([['label' => 'Godmother', 'name' => 'Mary']], $contact['relations'], 'a row with no name is dropped, a custom label kept');
        self::assertSame([['label' => 'blog', 'url' => 'https://blog.example']], $contact['urls']);
        self::assertSame([['service' => 'sip', 'handle' => 'haru@sip.example', 'label' => 'work']], $contact['ims']);
        self::assertSame([['label' => 'Employee ID', 'value' => '4711']], $contact['customFields']);

        $this->call(['update' => [$id => ['relations' => [], 'birthday' => null]]]);
        $contact = $this->get->handle(['accountId' => $this->accountId(), 'ids' => [$id]], $this->context())['list'][0];

        self::assertSame([], $contact['relations'], 'a present list replaces the whole list');
        self::assertNull($contact['birthday']);
        self::assertSame([['label' => 'blog', 'url' => 'https://blog.example']], $contact['urls'], 'an absent one is left alone');
    }

    public function testADateThatIsNotOneIsRefused(): void
    {
        $result = $this->call(['create' => [
            'a' => ['displayName' => 'A', 'birthday' => '1980-02-30'],
            'b' => ['displayName' => 'B', 'dates' => [['label' => 'other', 'date' => 'soon']]],
            'c' => ['displayName' => 'C', 'urls' => 'https://example.com'],
        ]]);

        self::assertSame('invalidProperties', $result['notCreated']['a']['type']);
        self::assertSame('invalidProperties', $result['notCreated']['b']['type']);
        self::assertSame('invalidProperties', $result['notCreated']['c']['type']);
    }

    public function testAnUpdateToASyncedContactIsQueuedForItsServer(): void
    {
        $book    = $this->fixture->book($this->user);
        $contact = $this->fixture->contact($this->user, 'Ada Lovelace', ['ada@example.com'], $book);
        $id      = (string) $contact->id;

        $result = $this->call(['update' => [$id => [
            'company' => 'Analytical Engines',
            'phones'  => [['phone' => '+44 20 7946 0000', 'label' => 'mobile']],
        ]]]);

        self::assertArrayHasKey($id, (array) $result['updated']);

        $this->em->refresh($contact);
        self::assertSame(SyncState::PendingUpdate, $contact->syncState);
        self::assertSame('mobile', $contact->phones->first()->label, 'phones may say mobile');
    }

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function call(array $arguments): array
    {
        return $this->set->handle(array_merge(['accountId' => $this->accountId()], $arguments), $this->context());
    }
}
