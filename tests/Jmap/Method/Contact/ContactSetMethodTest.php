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
