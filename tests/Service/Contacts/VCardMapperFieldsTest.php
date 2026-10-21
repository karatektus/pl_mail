<?php

declare(strict_types=1);

namespace App\Tests\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\VCardMapper;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * The fields beyond names, emails, phones and addresses — dates, related
 * people, websites, chat handles, custom fields, phonetic names, department,
 * office — and the photo, through a vCard and back.
 *
 * What matters is the same as in VCardMapperTest: every spelling the common
 * clients write is read, an unchanged list is left exactly as the server
 * wrote it, and a changed one is written in the spelling the most clients
 * read.
 */
final class VCardMapperFieldsTest extends TestCase
{
    private VCardMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new VCardMapper();
    }

    public function testReadsEverySpellingTheCommonClientsWrite(): void
    {
        $parsed = $this->mapper->read($this->richCard()) ?? self::fail('fixture');

        self::assertSame('Dr.', $parsed->namePrefix);
        self::assertSame('PhD', $parsed->nameSuffix);
        self::assertSame('ハル', $parsed->phoneticGivenName);
        self::assertSame('タナカ', $parsed->phoneticFamilyName);
        self::assertSame('アナリティカル', $parsed->phoneticCompany);
        self::assertSame('Research', $parsed->department);
        self::assertSame('Building 2, room 14', $parsed->officeLocation);
        self::assertSame('--05-12', $parsed->birthday, "Apple's 1604 is a birthday without a year");
        self::assertSame([
            ['label' => 'anniversary', 'date' => '2010-06-01'],
            ['label' => 'First met', 'date' => '2009-01-15'],
        ], $parsed->dates, 'a custom X-ABLabel is kept as typed');
        self::assertSame([
            ['label' => 'Godmother', 'name' => 'Mary'],
            ['label' => 'spouse', 'name' => 'Kenji Tanaka'],
            ['label' => 'manager', 'name' => 'Grace'],
        ], $parsed->relations, "Apple's spelling first, then Evolution's");
        self::assertSame([
            ['label' => 'work', 'url' => 'https://example.com'],
            ['label' => 'blog', 'url' => 'https://blog.example.com'],
        ], $parsed->urls);
        self::assertSame([
            ['service' => 'skype', 'handle' => 'haru.t', 'label' => 'work'],
            ['service' => 'sip', 'handle' => 'haru@sip.example', 'label' => 'other'],
            ['service' => 'xmpp', 'handle' => 'haru@jabber.example', 'label' => 'home'],
        ], $parsed->ims, 'IMPP, its X-SERVICE-TYPE, and the X-JABBER of older clients — the same handle once');
        self::assertSame([['label' => 'Employee ID', 'value' => '4711']], $parsed->customFields);
    }

    public function testAnUnchangedListKeepsItsPropertiesAsTheServerWroteThem(): void
    {
        $contact = $this->contactFrom($this->richCard());
        $contact->nickname = 'Haru';

        $written = $this->mapper->write($this->richCard(), $contact);

        $names = ['X-ABDATE', 'X-ABRELATEDNAMES', 'X-ABLABEL', 'X-JABBER', 'IMPP', 'URL', 'BDAY', 'X-ANNIVERSARY', 'X-SPOUSE', 'X-MANAGER', 'X-PLMAIL-FIELD'];

        self::assertSame($this->propertyLines($this->richCard(), $names), $this->propertyLines($written, $names));
        self::assertStringContainsString('NICKNAME:Haru', $written);
    }

    public function testAChangedListIsWrittenInTheSpellingMostClientsRead(): void
    {
        $contact            = $this->contactFrom($this->richCard());
        $contact->relations = [['label' => 'child', 'name' => 'Aiko'], ['label' => 'Godmother', 'name' => 'Mary']];
        $contact->dates     = [['label' => 'anniversary', 'date' => '--06-01']];
        $contact->ims       = [['service' => 'qq', 'handle' => '12345', 'label' => 'other']];

        $card = $this->parse($this->mapper->write($this->richCard(), $contact));

        self::assertSame([], $card->select('X-SPOUSE'), 'every property the list was read from goes');
        self::assertSame([], $card->select('X-MANAGER'));
        self::assertSame([], $card->select('X-JABBER'));
        self::assertSame(['Aiko', 'Mary'], array_map(strval(...), $card->select('X-ABRELATEDNAMES')));
        self::assertSame(['_$!<Child>!$_', 'Godmother'], $this->labelsOf($card, 'X-ABRELATEDNAMES'), "Apple's own label for its own relation, plain text for a custom one");
        self::assertSame('1604-06-01', (string) $card->select('X-ANNIVERSARY')[0], 'a 3.0 card has no spelling for a date without a year but Apple\'s');
        self::assertSame('1604', (string) $card->select('X-ANNIVERSARY')[0]['X-APPLE-OMIT-YEAR']);
        self::assertSame([], $card->select('X-ABDATE'));
        self::assertSame('x-apple:12345', (string) $card->select('IMPP')[0]);
        self::assertSame('Qq', (string) $card->select('IMPP')[0]['X-SERVICE-TYPE']);
        self::assertSame(1, count(array_filter($card->select('X-ABLABEL'), static fn (Property $p): bool => '_$!<Work>!$_' === (string) $p)), 'the label of the work email, in item1, is not touched');

        $read = $this->mapper->read($card->serialize()) ?? self::fail('written card');

        self::assertSame($contact->relations, $read->relations, 'and it reads back as what was written');
        self::assertSame($contact->dates, $read->dates);
        self::assertSame($contact->ims, $read->ims);
    }

    public function testAVersion4CardGetsVersion4Properties(): void
    {
        $contact            = $this->contactFrom("BEGIN:VCARD\r\nVERSION:4.0\r\nUID:v4\r\nFN:Ada\r\nEND:VCARD\r\n");
        $contact->birthday  = '--12-10';
        $contact->relations = [['label' => 'spouse', 'name' => 'William']];
        $contact->dates     = [['label' => 'anniversary', 'date' => '1835-07-08']];

        $card = $this->parse($this->mapper->write("BEGIN:VCARD\r\nVERSION:4.0\r\nUID:v4\r\nFN:Ada\r\nEND:VCARD\r\n", $contact));

        self::assertSame('--1210', (string) $card->select('BDAY')[0]);
        self::assertSame('William', (string) $card->select('RELATED')[0]);
        self::assertSame('spouse', strtolower((string) $card->select('RELATED')[0]['TYPE']));
        self::assertSame('18350708', (string) $card->select('ANNIVERSARY')[0], "RFC 6350's basic format");

        $read = $this->mapper->read($card->serialize()) ?? self::fail('written card');

        self::assertSame('--12-10', $read->birthday);
        self::assertSame($contact->relations, $read->relations);
        self::assertSame($contact->dates, $read->dates);
    }

    public function testTheNewScalarsGoWhereTheyAreRead(): void
    {
        $contact                     = Contact::create(new User(), 'Haru Tanaka');
        $contact->namePrefix         = 'Dr.';
        $contact->givenName          = 'Haru';
        $contact->middleName         = 'K.';
        $contact->familyName         = 'Tanaka';
        $contact->nameSuffix         = 'PhD';
        $contact->phoneticGivenName  = 'ハル';
        $contact->phoneticFamilyName = 'タナカ';
        $contact->company            = 'Analytical Engines';
        $contact->department         = 'Research';
        $contact->officeLocation     = 'Room 14';
        $contact->urls               = [['label' => 'profile', 'url' => 'https://example.com/haru']];
        $contact->customFields       = [['label' => 'Employee ID', 'value' => '4711']];

        $card = $this->parse($this->mapper->export($contact));

        self::assertSame('Tanaka;Haru;K.;Dr.;PhD', (string) $card->select('N')[0]);
        self::assertSame('Analytical Engines;Research', (string) $card->select('ORG')[0]);
        self::assertSame('ハル', (string) $card->select('X-PHONETIC-FIRST-NAME')[0]);
        self::assertSame('Room 14', (string) $card->select('X-PLMAIL-OFFICE')[0]);
        self::assertSame('4711', (string) $card->select('X-PLMAIL-FIELD')[0]);
        self::assertSame('Employee ID', (string) $card->select('X-PLMAIL-FIELD')[0]['X-LABEL']);
        self::assertSame([], $card->validate(), 'a server must accept it');

        $read = $this->mapper->read($card->serialize()) ?? self::fail('written card');

        self::assertSame('K.', $read->middleName);
        self::assertSame('Research', $read->department);
        self::assertSame($contact->urls, $read->urls, 'a label TYPE cannot say goes in an X-ABLabel');
        self::assertSame($contact->customFields, $read->customFields);
    }

    // ── The photo ─────────────────────────────────────────────────────────────

    public function testAPhotoNotChangedHereStaysByteForByte(): void
    {
        $contact = $this->contactFrom($this->photoCard());

        $written = $this->mapper->write($this->photoCard(), $contact, new ContactPhoto('other bytes', 'image/png'));

        self::assertStringContainsString('PHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQ', $written);
    }

    public function testAPhotoChangedHereReplacesTheCardsAndARemovedOneRemovesIt(): void
    {
        $contact             = $this->contactFrom($this->photoCard());
        $contact->photoDirty = true;

        $replaced = $this->parse($this->mapper->write($this->photoCard(), $contact, new ContactPhoto("\x89PNG new", 'image/png')));

        self::assertSame("\x89PNG new", $replaced->select('PHOTO')[0]->getValue());
        self::assertSame('PNG', strtoupper((string) $replaced->select('PHOTO')[0]['TYPE']));

        $removed = $this->parse($this->mapper->write($this->photoCard(), $contact, null));

        self::assertSame([], $removed->select('PHOTO'));
    }

    public function testANewCardCarriesThePhotoInline(): void
    {
        $contact = Contact::create(new User(), 'Ada');

        $v3 = $this->parse($this->mapper->write(null, $contact, new ContactPhoto('jpeg bytes', 'image/jpeg')));

        self::assertSame('jpeg bytes', $v3->select('PHOTO')[0]->getValue());

        $parsed = $this->mapper->read($v3->serialize()) ?? self::fail('written card');

        self::assertSame('jpeg bytes', $parsed->photoBytes);
        self::assertSame(sha1('jpeg bytes'), $parsed->photoVersion, 'what tells a pull the photo is the one it has');
        self::assertFalse($parsed->photoAbsent);
    }

    public function testACardWithoutAPhotoSaysSoAndOneWithALinkDoesNot(): void
    {
        self::assertTrue($this->mapper->read("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nEND:VCARD\r\n")?->photoAbsent);

        $linked = $this->mapper->read("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nPHOTO;VALUE=uri:https://example.com/a.jpg\r\nEND:VCARD\r\n");

        self::assertFalse($linked?->photoAbsent, 'a photo we do not fetch is still a photo');
        self::assertNull($linked->photoBytes);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function richCard(): string
    {
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:rich-1',
            'N:Tanaka;Haru;;Dr.;PhD',
            'FN:Haru Tanaka',
            'ORG:Analytical Engines;Research',
            'X-PHONETIC-FIRST-NAME:ハル',
            'X-PHONETIC-LAST-NAME:タナカ',
            'X-PHONETIC-ORG:アナリティカル',
            'X-PLMAIL-OFFICE:Building 2\, room 14',
            'item1.EMAIL;type=INTERNET;type=WORK:haru@work.example',
            'item1.X-ABLabel:_$!<Work>!$_',
            'BDAY;X-APPLE-OMIT-YEAR=1604:1604-05-12',
            'X-ANNIVERSARY:2010-06-01',
            'item2.X-ABDATE:2009-01-15',
            'item2.X-ABLabel:First met',
            'X-SPOUSE:Kenji Tanaka',
            'item3.X-ABRELATEDNAMES:Mary',
            'item3.X-ABLabel:Godmother',
            'X-MANAGER:Grace',
            'URL;type=WORK:https://example.com',
            'item4.URL:https://blog.example.com',
            'item4.X-ABLabel:blog',
            'IMPP;X-SERVICE-TYPE=Skype;type=WORK:skype:haru.t',
            'IMPP:sip:haru@sip.example',
            'IMPP;type=HOME:xmpp:haru@jabber.example',
            'X-JABBER;type=HOME:haru@jabber.example',
            'X-PLMAIL-FIELD;X-LABEL=Employee ID:4711',
            'END:VCARD',
            '',
        ]);
    }

    private function photoCard(): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:p\r\nFN:Ada\r\nPHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP\r\nEND:VCARD\r\n";
    }

    private function contactFrom(string $vcard): Contact
    {
        $contact = Contact::create(new User(), 'x');
        $this->mapper->apply($this->mapper->read($vcard) ?? self::fail('fixture'), $contact);

        return $contact;
    }

    /**
     * The serialised lines of the named properties, unfolded, so a test can
     * say "this line went back exactly as it came".
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function propertyLines(string $vcard, array $names): array
    {
        $lines = [];

        foreach ($this->parse($vcard)->children() as $child) {
            if ($child instanceof Property && in_array(strtoupper($child->name), $names, true)) {
                $lines[] = trim($child->serialize());
            }
        }

        return $lines;
    }

    /**
     * @return list<string> the X-ABLabel beside each $name property, in order
     */
    private function labelsOf(VCard $card, string $name): array
    {
        $labels = [];

        foreach ($card->select($name) as $property) {
            foreach ($card->select('X-ABLABEL') as $label) {
                if (0 === strcasecmp((string) $label->group, (string) $property->group)) {
                    $labels[] = (string) $label;
                }
            }
        }

        return $labels;
    }

    private function parse(string $vcard): VCard
    {
        $card = Reader::read($vcard);

        self::assertInstanceOf(VCard::class, $card);

        return $card;
    }
}
