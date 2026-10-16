<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook\CardDav;

use App\Domain\DTO\AddressBook\VCardFields;
use App\Service\AddressBook\CardDav\VCardConverter;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * vCards in and out.
 *
 * The claim that matters is the second half: **an edit made here changes the
 * fields plMail owns and nothing else.** A contact on somebody's phone carries
 * a photo, birthdays, postal addresses and properties only that phone
 * understands; a write that rebuilt the card from five fields would strip all
 * of it the first time a typo was fixed here, on every device the address book
 * syncs to.
 */
final class VCardConverterTest extends TestCase
{
    private VCardConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new VCardConverter();
    }

    public function testReadsTheFieldsPlMailShows(): void
    {
        $fields = $this->converter->read($this->appleCard(), 'https://dav.example.com/ab/1.vcf');

        self::assertNotNull($fields);
        self::assertSame('apple-uid-1', $fields->uid);
        self::assertSame('Ada Lovelace', $fields->fullName);
        self::assertSame(['ada@example.com', 'ada.l@work.example'], $fields->emails, 'lower-cased, in card order');
        self::assertSame(['+44 20 7946 0000'], $fields->phones);
        self::assertSame('Analytical Engines', $fields->organization, 'the name, without the unit');
        self::assertSame('Met at the Royal Society', $fields->note);
    }

    public function testACardWithoutUidTakesTheFallbackSoItKeepsOneIdentity(): void
    {
        $fields = $this->converter->read("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Uid\r\nEND:VCARD\r\n", 'https://dav.example.com/ab/x.vcf');

        self::assertSame('https://dav.example.com/ab/x.vcf', $fields?->uid);
    }

    public function testACardWithOnlyNIsNamedFromIt(): void
    {
        $fields = $this->converter->read("BEGIN:VCARD\r\nVERSION:2.1\r\nN:Hopper;Grace;;;\r\nEND:VCARD\r\n", 'x');

        self::assertSame('Grace Hopper', $fields?->fullName);
    }

    public function testInvalidAddressesAndDuplicatesAreDropped(): void
    {
        $fields = $this->converter->read(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEMAIL:not-an-address\r\nEMAIL:A@Example.com\r\nEMAIL:a@example.com\r\nEND:VCARD\r\n",
            'x',
        );

        self::assertSame(['a@example.com'], $fields?->emails);
    }

    public function testSomethingThatIsNotAVCardIsRefused(): void
    {
        self::assertNull($this->converter->read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", 'x'));
        self::assertNull($this->converter->read('<html>login</html>', 'x'));
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /** THE one: what plMail has no field for survives the edit. */
    public function testAnEditKeepsEverythingPlMailDoesNotOwn(): void
    {
        $edited = $this->converter->write($this->appleCard(), new VCardFields(
            uid:          'apple-uid-1',
            fullName:     'Ada Lovelace',
            emails:       ['ada@example.com', 'ada.l@work.example'],
            phones:       ['+44 20 7946 0000', '+44 7700 900123'],
            organization: 'Analytical Engines',
            note:         'Met at the Royal Society',
        ));

        $card = $this->parse($edited);

        self::assertSame('1815-12-10', $this->value($card, 'BDAY'), 'the birthday survived');
        self::assertNotSame([], $card->select('PHOTO'), 'and the photo');
        self::assertSame('ada-im', $this->value($card, 'X-SOCIALPROFILE'), 'and the property only a phone understands');
        self::assertNotSame([], $card->select('ADR'), 'and the postal address');
        self::assertSame('apple-uid-1', $this->value($card, 'UID'));

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

        // The new number is appended; the old one is untouched.
        self::assertCount(2, $card->select('TEL'));
    }

    public function testRemovedValuesAreRemovedAndNothingElse(): void
    {
        $edited = $this->converter->write($this->appleCard(), new VCardFields(
            uid:      'apple-uid-1',
            fullName: 'Ada Lovelace',
            emails:   ['ada@example.com'],
        ));

        $card = $this->parse($edited);

        self::assertCount(1, $card->select('EMAIL'));
        self::assertCount(0, $card->select('TEL'));
        self::assertSame([], $card->select('ORG'));
        self::assertSame([], $card->select('NOTE'));
        self::assertNotSame([], $card->select('PHOTO'));
    }

    /**
     * A carefully structured N cannot be rebuilt from FN, so it is left alone
     * unless the name itself was changed.
     */
    public function testTheStructuredNameIsOnlyRewrittenWhenTheNameChanged(): void
    {
        $fields = new VCardFields(uid: 'apple-uid-1', fullName: 'Ada Lovelace', emails: ['ada@example.com']);

        self::assertSame('Lovelace;Augusta Ada;King;Countess;', $this->value($this->parse($this->converter->write($this->appleCard(), $fields)), 'N'));

        $renamed = new VCardFields(uid: 'apple-uid-1', fullName: 'Ada King', emails: ['ada@example.com']);

        self::assertSame('King;Ada;;;', $this->value($this->parse($this->converter->write($this->appleCard(), $renamed)), 'N'));
    }

    public function testAContactCreatedHereIsAComplete3Card(): void
    {
        $vcard = $this->converter->write(null, new VCardFields(
            uid:          'new-uid',
            fullName:     'Grace Brewster Hopper',
            emails:       ['grace@navy.example'],
            phones:       ['555-0100'],
            organization: 'US Navy',
            note:         null,
        ));

        $card = $this->parse($vcard);

        self::assertSame('3.0', $this->value($card, 'VERSION'));
        self::assertSame('new-uid', $this->value($card, 'UID'));
        self::assertSame('Grace Brewster Hopper', $this->value($card, 'FN'));
        self::assertSame('Hopper;Grace Brewster;;;', $this->value($card, 'N'));
        self::assertSame('grace@navy.example', $this->value($card, 'EMAIL'));
        self::assertSame('555-0100', $this->value($card, 'TEL'));
        self::assertSame('US Navy', $this->value($card, 'ORG'));
        self::assertSame([], $card->validate(), 'a server must accept it');

        $read = $this->converter->read($vcard, 'x');

        self::assertSame(['grace@navy.example'], $read?->emails, 'and it reads back as what was written');
    }

    public function testAnUnchangedOrganisationKeepsItsUnit(): void
    {
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u\r\nFN:X\r\nORG:Acme;Research\r\nEND:VCARD\r\n";

        $card = $this->parse($this->converter->write($vcard, new VCardFields(uid: 'u', fullName: 'X', organization: 'Acme')));

        self::assertSame('Acme;Research', $this->value($card, 'ORG'));
    }

    public function testNewUidsAreUnique(): void
    {
        self::assertNotSame($this->converter->newUid(), $this->converter->newUid());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

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
