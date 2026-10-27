<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook\Jmap;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Service\AddressBook\Jmap\JsContactMapper;
use PHPUnit\Framework\TestCase;

/**
 * JSContact cards to plMail's contact model and back (#27).
 *
 * The cases are the ones a translation gets wrong quietly: the preferred
 * address first, a mobile told apart by its feature rather than its context,
 * a birthday without a year, and on the way out every managed property named
 * — a value removed here has to be removed there.
 */
final class JsContactMapperTest extends TestCase
{
    public function testACardReadsAsAParsedContact(): void
    {
        $parsed = new JsContactMapper()->toParsed([
            '@type'         => 'Card',
            'uid'           => 'urn:uuid:ada',
            'name'          => ['full' => 'Ada Lovelace', 'components' => [
                ['kind' => 'given', 'value' => 'Ada'],
                ['kind' => 'surname', 'value' => 'Lovelace'],
            ]],
            'organizations' => ['o' => ['name' => 'Analytical Engines', 'units' => [['name' => 'Research']]]],
            'emails'        => [
                'e1' => ['address' => 'ada@home.test', 'contexts' => ['private' => true]],
                'e2' => ['address' => 'ada@work.test', 'contexts' => ['work' => true], 'pref' => 1],
            ],
            'phones'        => ['p1' => ['number' => '+44 1', 'features' => ['mobile' => true], 'contexts' => ['private' => true]]],
            'addresses'     => ['a1' => ['contexts' => ['work' => true], 'components' => [
                ['kind' => 'number', 'value' => '12'],
                ['kind' => 'name', 'value' => 'St James Square'],
                ['kind' => 'locality', 'value' => 'London'],
                ['kind' => 'country', 'value' => 'UK'],
            ]]],
            'anniversaries' => ['b' => ['kind' => 'birth', 'date' => ['@type' => 'PartialDate', 'month' => 12, 'day' => 10]]],
            'keywords'      => ['friends' => true],
            'media'         => ['m' => ['kind' => 'photo', 'blobId' => 'blob-ada', 'mediaType' => 'image/png']],
        ]);

        self::assertSame('urn:uuid:ada', $parsed->uid);
        self::assertSame('Ada Lovelace', $parsed->displayName);
        self::assertSame('Ada', $parsed->givenName);
        self::assertSame('Lovelace', $parsed->familyName);
        self::assertSame('Analytical Engines', $parsed->company);
        self::assertSame('Research', $parsed->department);
        self::assertSame(['email' => 'ada@work.test', 'label' => 'work', 'primary' => true], $parsed->emails[0], 'the preferred address leads');
        self::assertSame('home', $parsed->emails[1]['label']);
        self::assertSame('mobile', $parsed->phones[0]['label'], 'a mobile by its feature, not its context');
        self::assertSame('12 St James Square', $parsed->addresses[0]['street']);
        self::assertSame('London', $parsed->addresses[0]['city']);
        self::assertSame('--12-10', $parsed->birthday, 'a birthday without a year');
        self::assertSame(['friends'], $parsed->tags);
        self::assertTrue($parsed->photoDeferred);
        self::assertFalse($parsed->photoAbsent);
    }

    public function testACardWithNoPhotoSaysSo(): void
    {
        $parsed = new JsContactMapper()->toParsed(['@type' => 'Card', 'uid' => 'x']);

        self::assertTrue($parsed->photoAbsent, 'so a photo removed on the server is removed here');
    }

    public function testAContactWritesEveryManagedProperty(): void
    {
        $contact              = new Contact();
        $contact->uid         = 'urn:uuid:ada';
        $contact->displayName = 'Ada Lovelace';
        $contact->givenName   = 'Ada';
        $contact->birthday    = '1815-12-10';

        ContactEmail::create($contact, 'ada@work.test', 'work', true);
        ContactPhone::create($contact, '+44 1', 'mobile');
        ContactAddress::create($contact, 'home')->city = 'London';

        $card = new JsContactMapper()->toCard($contact);

        foreach (JsContactMapper::MANAGED as $property) {
            self::assertArrayHasKey($property, $card, sprintf('%s is named, so a removal reaches the server', $property));
        }

        self::assertNull($card['notes'], 'no notes is written as none');
        self::assertSame('Ada Lovelace', $card['name']['full']);
        self::assertSame(['address' => 'ada@work.test', 'contexts' => ['work' => true], 'pref' => 1], $card['emails']['e0']);
        self::assertSame(['mobile' => true], $card['phones']['p0']['features']);
        self::assertSame([['kind' => 'locality', 'value' => 'London']], $card['addresses']['a0']['components']);
        self::assertSame(['@type' => 'PartialDate', 'year' => 1815, 'month' => 12, 'day' => 10], $card['anniversaries']['birth']['date']);
    }
}
