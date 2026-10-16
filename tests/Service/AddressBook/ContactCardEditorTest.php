<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook;

use App\Domain\Enum\Calendar\SyncState;
use App\Entity\AddressBook\ContactCard;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Service\AddressBook\CardDav\VCardConverter;
use App\Service\AddressBook\ContactCardEditor;
use App\Tests\Service\AddressBook\CardDav\CardDavFixture;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The states an edit leaves a card in — which is what decides the request the
 * worker sends for it.
 *
 * The one easy to get wrong: a contact created here and edited again before
 * the worker ran is still a *create*. Marking it PendingUpdate would send a PUT
 * with If-Match and no etag to an href that does not exist yet.
 */
final class ContactCardEditorTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    /** @var list<object> */
    private array $dispatched = [];

    public function testACreatedContactIsAPendingCreateWithAFreshUid(): void
    {
        $book = CardDavFixture::book();

        $card = $this->editor()->create($book, 'Grace Hopper', ['Grace@Navy.example'], ['555-0100'], 'US Navy', null);

        self::assertSame(SyncState::PendingCreate, $card->syncState);
        self::assertNotSame('', $card->uid);
        self::assertSame(['grace@navy.example'], $card->emails, 'normalised like every other address');
        self::assertStringContainsString('UID:' . $card->uid, $card->vcard);
        self::assertSame([$card], $this->persisted);
    }

    public function testAContactWithOnlyAnAddressIsNamedByIt(): void
    {
        $card = $this->editor()->create(CardDavFixture::book(), '', ['ada@example.com'], [], null, null);

        self::assertSame('ada@example.com', $card->fullName);
        self::assertStringContainsString('FN:ada@example.com', $card->vcard);
    }

    public function testEditingAnUnsentContactKeepsItACreate(): void
    {
        $editor = $this->editor();
        $card   = $editor->create(CardDavFixture::book(), 'Grace Hopper', ['grace@navy.example'], [], null, null);

        $editor->update($card, 'Rear Admiral Grace Hopper', ['grace@navy.example'], [], null, null);

        self::assertSame(SyncState::PendingCreate, $card->syncState);
        self::assertSame('Rear Admiral Grace Hopper', $card->fullName);
    }

    public function testEditingASyncedContactMakesItAnUpdate(): void
    {
        $card        = new ContactCard(CardDavFixture::book(), 'ada');
        $card->href  = 'https://dav.example.com/dav/addressbooks/alice/contacts/ada.vcf';
        $card->vcard = CardDavFixture::vcard('ada', 'Ada Lovelace', 'ada@example.com');

        $this->editor()->update($card, 'Ada King', ['ada@example.com'], [], null, null);

        self::assertSame(SyncState::PendingUpdate, $card->syncState);
    }

    public function testDeletingAnUnsentContactRemovesItWithoutAskingTheServer(): void
    {
        $card = new ContactCard(CardDavFixture::book(), 'local');

        $this->editor()->delete($card);

        self::assertSame([$card], $this->removed);
    }

    public function testDeletingASyncedContactWaitsForTheServer(): void
    {
        $card       = new ContactCard(CardDavFixture::book(), 'ada');
        $card->href = 'https://dav.example.com/dav/addressbooks/alice/contacts/ada.vcf';

        $this->editor()->delete($card);

        self::assertSame(SyncState::PendingDelete, $card->syncState);
        self::assertSame([], $this->removed, 'the row stays until the DELETE has gone out');
    }

    public function testAReadOnlyBookRefusesEveryWrite(): void
    {
        $book             = CardDavFixture::book();
        $book->isReadOnly = true;

        $this->expectException(LogicException::class);

        $this->editor()->create($book, 'Nobody', ['nobody@example.com'], [], null, null);
    }

    public function testNothingIsQueuedForABookWithNoIdYet(): void
    {
        $this->editor()->create(CardDavFixture::book(), 'Grace', ['g@example.com'], [], null, null);

        self::assertSame([], array_filter($this->dispatched, static fn (object $m): bool => $m instanceof SyncAddressBookMessage));
    }

    private function editor(): ContactCardEditor
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new ContactCardEditor(new VCardConverter(), $em, $bus);
    }
}
