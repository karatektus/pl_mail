<?php

declare(strict_types=1);

namespace App\Tests\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Entity\User\User;
use App\Service\Contacts\ParsedContact;
use App\Service\Contacts\VCardMapper;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * vCards in and out, for the sync and for import and export alike.
 *
 * The claim that matters is the writing half: **an edit made here changes the
 * properties plMail models and nothing else.** A contact on somebody's phone
 * carries a photo, a birthday and properties only that phone understands; a
 * write that rebuilt the card from the rows would strip all of it the first
 * time a typo was fixed here, on every device the address book syncs to.
 */
final class VCardMapperTest extends TestCase
{
    private VCardMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new VCardMapper();
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    public function testReadsWhatTheRowsModel(): void
    {
        $parsed = $this->mapper->read($this->appleCard());

        self::assertNotNull($parsed);
        self::assertSame('apple-uid-1', $parsed->uid);
        self::assertSame('Ada Lovelace', $parsed->displayName);
        self::assertSame('Augusta Ada', $parsed->givenName);
        self::assertSame('King', $parsed->middleName);
        self::assertSame('Lovelace', $parsed->familyName);
        self::assertSame('Countess', $parsed->namePrefix);
        self::assertSame('Analytical Engines', $parsed->company, 'the name, without the unit');
        self::assertSame('Mathematics', $parsed->department, 'the first unit');
        self::assertSame('1815-12-10', $parsed->birthday);
        self::assertSame('Met at the Royal Society', $parsed->notes);
        self::assertSame(['friends', 'science'], $parsed->tags);
        self::assertSame([
            ['email' => 'ada@example.com', 'label' => 'home', 'primary' => true],
            ['email' => 'ada.l@work.example', 'label' => 'work', 'primary' => false],
        ], $parsed->emails, 'lower-cased, labelled, the preferred one marked');
        self::assertSame([['phone' => '+44 20 7946 0000', 'label' => 'mobile', 'primary' => true]], $parsed->phones);
        self::assertSame('London', $parsed->addresses[0]['city']);
        self::assertSame('home', $parsed->addresses[0]['label']);
        self::assertSame('image/jpeg', $parsed->photoMime, 'an inline photo is handed over for the photo store');
    }

    public function testACardWithOnlyNIsNamedFromIt(): void
    {
        $parsed = $this->mapper->read("BEGIN:VCARD\r\nVERSION:2.1\r\nN:Hopper;Grace;;;\r\nEND:VCARD\r\n");

        self::assertNotNull($parsed);
        self::assertSame('Grace Hopper', $this->mapper->displayNameOf($parsed));
    }

    public function testInvalidAddressesAndDuplicatesAreDropped(): void
    {
        $parsed = $this->mapper->read("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEMAIL:not-an-address\r\nEMAIL:A@Example.com\r\nEMAIL:a@example.com\r\nEND:VCARD\r\n");

        self::assertSame(['a@example.com'], array_column($parsed->emails ?? [], 'email'));
    }

    public function testSomethingThatIsNotAVCardIsRefused(): void
    {
        self::assertNull($this->mapper->read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"));
        self::assertNull($this->mapper->read('<html>login</html>'));
    }

    /** A .vcf export from a phone: every readable card, in order, the broken one skipped. */
    public function testAFileOfCardsIsReadCardByCard(): void
    {
        $file = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:One\r\nEND:VCARD\r\n"
            . "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Two\r\nEMAIL;PREF=1:two@example.com\r\nEND:VCARD\r\n"
            . "BEGIN:VCARD\r\nVERSION:2.1\r\nN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:M=C3=BCller;J=C3=BCrgen;;;\r\nEND:VCARD\r\n";

        $parsed = $this->mapper->parseAll($file);

        self::assertSame(['One', 'Two', null], array_map(static fn (ParsedContact $p): ?string => $p->displayName, $parsed));
        self::assertTrue($parsed[1]->emails[0]['primary'], 'vCard 4.0 says PREF=1');
        self::assertSame('Müller', $parsed[2]->familyName, 'quoted-printable, which the hand-written parser did not read');
    }

    public function testApplyingReconcilesRowsByValue(): void
    {
        $contact = $this->contactFrom($this->appleCard());
        $email   = $contact->emails->first();

        self::assertInstanceOf(ContactEmail::class, $email);

        $this->mapper->apply($this->mapper->read(str_replace('item1.EMAIL;type=INTERNET;type=WORK:ada.l@work.example', '', $this->appleCard())) ?? self::fail(), $contact);

        self::assertCount(1, $contact->emails);
        self::assertSame($email, $contact->emails->first(), 'the row that is still there is the same row');
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /** THE one: what plMail has no field for survives the edit. */
    public function testAnEditKeepsEverythingPlMailDoesNotOwn(): void
    {
        $contact = $this->contactFrom($this->appleCard());
        ContactPhone::create($contact, '+447700900123', 'work');

        $card = $this->parse($this->mapper->write($this->appleCard(), $contact));

        self::assertSame('1815-12-10', $this->value($card, 'BDAY'), 'the birthday survived');
        self::assertNotSame([], $card->select('PHOTO'), 'and the photo');
        self::assertSame('ada-im', $this->value($card, 'X-SOCIALPROFILE'), 'and the property only a phone understands');
        self::assertSame('apple-uid-1', $this->value($card, 'UID'));
        self::assertSame('Lovelace;Augusta Ada;King;Countess;', $this->value($card, 'N'), 'an unchanged name keeps its structure');
        self::assertSame('Analytical Engines;Mathematics', $this->value($card, 'ORG'), 'an unchanged organisation keeps its unit');

        // The kept address keeps its TYPE and its Apple grouping, so a phone
        // that filed it under Work still does.
        $work = null;

        foreach ($card->select('EMAIL') as $email) {
            if ('ada.l@work.example' === (string) $email) {
                $work = $email;
            }
        }

        self::assertNotNull($work);
        self::assertSame('ITEM1', strtoupper((string) $work->group), 'sabre upper-cases groups; the grouping is what matters');
        self::assertStringContainsStringIgnoringCase('work', (string) $work['TYPE']);

        // The new number is appended; the old one is untouched, formatting and all.
        $phones = array_map(static fn ($p): string => (string) $p, $card->select('TEL'));
        self::assertSame(['+44 20 7946 0000', '+447700900123'], $phones);
    }

    public function testRemovedValuesAreRemovedAndNothingElse(): void
    {
        $contact = $this->contactFrom($this->appleCard());

        foreach ($contact->phones->toArray() as $phone) {
            $contact->phones->removeElement($phone);
        }

        $contact->company    = null;
        $contact->department = null;
        $contact->notes      = null;
        $contact->birthday   = null;

        $card = $this->parse($this->mapper->write($this->appleCard(), $contact));

        self::assertCount(2, $card->select('EMAIL'));
        self::assertCount(0, $card->select('TEL'));
        self::assertSame([], $card->select('ORG'));
        self::assertSame([], $card->select('NOTE'));
        self::assertSame([], $card->select('BDAY'));
        self::assertNotSame([], $card->select('PHOTO'), 'a photo not changed here stays');
        self::assertNotSame([], $card->select('X-SOCIALPROFILE'));
    }

    public function testARenameRewritesTheStructuredNameAndKeepsItsOtherParts(): void
    {
        $contact             = $this->contactFrom($this->appleCard());
        $contact->givenName  = 'Ada';
        $contact->familyName = 'Byron';

        self::assertSame('Byron;Ada;King;Countess;', $this->value($this->parse($this->mapper->write($this->appleCard(), $contact)), 'N'));
    }

    public function testARelabelledAddressChangesItsTypeOnly(): void
    {
        $contact = $this->contactFrom($this->appleCard());

        foreach ($contact->emails as $email) {
            if ('ada.l@work.example' === $email->email) {
                $email->label = 'home';
            }
        }

        $card = $this->parse($this->mapper->write($this->appleCard(), $contact));

        foreach ($card->select('EMAIL') as $email) {
            if ('ada.l@work.example' === (string) $email) {
                $types = strtoupper((string) $email['TYPE']);
                self::assertStringContainsString('HOME', $types);
                self::assertStringNotContainsString('WORK', $types);
                self::assertStringContainsString('INTERNET', $types, 'the other TYPE values stay');
                self::assertSame('ITEM1', strtoupper((string) $email->group));
            }
        }
    }

    public function testALocalContactBecomesAComplete3Card(): void
    {
        $contact = Contact::create(new User(), 'Grace Brewster Hopper');
        $contact->company = 'US Navy';
        $contact->tags    = ['navy'];
        ContactEmail::create($contact, 'grace@navy.example', 'work', true);
        ContactPhone::create($contact, '5550100', 'mobile');

        $vcard = $this->mapper->export($contact);
        $card  = $this->parse($vcard);

        self::assertSame('3.0', $this->value($card, 'VERSION'));
        self::assertSame($contact->uid, $this->value($card, 'UID'));
        self::assertSame('Grace Brewster Hopper', $this->value($card, 'FN'));
        self::assertSame('Hopper;Grace Brewster;;;', $this->value($card, 'N'));
        self::assertSame('grace@navy.example', $this->value($card, 'EMAIL'));
        self::assertSame('5550100', $this->value($card, 'TEL'));
        self::assertSame('US Navy', $this->value($card, 'ORG'));
        self::assertSame('navy', $this->value($card, 'CATEGORIES'));
        self::assertSame([], $card->validate(), 'a server must accept it');

        $read = $this->mapper->read($vcard);

        self::assertSame([['email' => 'grace@navy.example', 'label' => 'work', 'primary' => true]], $read?->emails, 'and it reads back as what was written');
        self::assertSame('mobile', $read->phones[0]['label']);
    }

    /** Written and read again, nothing has changed: a round trip is not an edit. */
    public function testWritingAnUnchangedContactChangesNothingItModels(): void
    {
        $contact = $this->contactFrom($this->appleCard());
        $again   = $this->mapper->read($this->mapper->write($this->appleCard(), $contact));

        self::assertEquals($this->mapper->read($this->appleCard()), $again);
    }

    /**
     * A provider spells an address as typed; the row keeps it lower-cased.
     * Matched as spelled, the row was removed and the same address inserted
     * again in one flush, which the unique key refused (prod, 2026-10-21).
     */
    public function testAnAddressInAnotherCaseIsTheSameRow(): void
    {
        $contact = Contact::create(new User(), 'Dominik');
        $kept    = ContactEmail::create($contact, 'dominik@example.com', 'work');

        $parsed         = new ParsedContact();
        $parsed->emails = [
            ['email' => 'Dominik@Example.com', 'label' => 'home', 'primary' => true],
            ['email' => ' DOMINIK@example.COM', 'label' => 'other'],
        ];

        $this->mapper->apply($parsed, $contact);

        self::assertCount(1, $contact->emails, 'two spellings of one address are one row');
        self::assertSame($kept, $contact->emails->first(), 'the row is kept, not removed and inserted again');
        self::assertSame('home', $kept->label, 'with the first spelling\'s label');
        self::assertTrue($kept->isPrimary);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function contactFrom(string $vcard): Contact
    {
        $contact = Contact::create(new User(), 'x');
        $this->mapper->apply($this->mapper->read($vcard) ?? self::fail('fixture'), $contact);

        return $contact;
    }

    /**
     * What an iPhone writes: grouped properties, TYPE parameters, a photo, and
     * things plMail has no field for.
     */
    private function appleCard(): string
    {
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'PRODID:-//Apple Inc.//iPhone OS 18.0//EN',
            'UID:apple-uid-1',
            'N:Lovelace;Augusta Ada;King;Countess;',
            'FN:Ada Lovelace',
            'ORG:Analytical Engines;Mathematics',
            'EMAIL;type=INTERNET;type=HOME;type=pref:Ada@Example.com',
            'item1.EMAIL;type=INTERNET;type=WORK:ada.l@work.example',
            'item1.X-ABLabel:_$!<Work>!$_',
            'TEL;type=CELL;type=VOICE;type=pref:+44 20 7946 0000',
            'ADR;type=HOME:;;12 St James\'s Square;London;;SW1Y 4JH;United Kingdom',
            'BDAY:1815-12-10',
            'NOTE:Met at the Royal Society',
            'CATEGORIES:friends,science',
            'X-SOCIALPROFILE;type=im:ada-im',
            'PHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP',
            'END:VCARD',
            '',
        ]);
    }

    /** The first value of a property, as text — '' when absent. */
    private function value(VCard $card, string $name): string
    {
        $property = $card->select($name)[0] ?? null;

        return null === $property ? '' : (string) $property;
    }

    private function parse(string $vcard): VCard
    {
        $card = Reader::read($vcard);

        self::assertInstanceOf(VCard::class, $card);

        return $card;
    }
}
