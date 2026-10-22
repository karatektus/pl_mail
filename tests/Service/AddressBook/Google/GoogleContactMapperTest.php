<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook\Google;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Service\AddressBook\Google\GoogleContactMapper;
use PHPUnit\Framework\TestCase;

/**
 * A People API Person and plMail's contact, field by field, in the shapes
 * Google's reference gives: every field plMail shows comes in and goes back
 * out, a custom type Google returns as typed stays as typed, and what plMail
 * edits only in part — the first organisation — keeps the rest.
 */
final class GoogleContactMapperTest extends TestCase
{
    public function testEveryFieldPlMailShowsIsRead(): void
    {
        $parsed = new GoogleContactMapper()->toParsed($this->person(), []);

        self::assertSame('Dr.', $parsed->namePrefix);
        self::assertSame('Haru', $parsed->givenName);
        self::assertSame('K.', $parsed->middleName);
        self::assertSame('Tanaka', $parsed->familyName);
        self::assertSame('PhD', $parsed->nameSuffix);
        self::assertSame('ハル', $parsed->phoneticGivenName);
        self::assertSame('タナカ', $parsed->phoneticFamilyName);
        self::assertSame('Analytical Engines', $parsed->company);
        self::assertSame('アナリティカル', $parsed->phoneticCompany);
        self::assertSame('Research', $parsed->department);
        self::assertSame('Room 14', $parsed->officeLocation);
        self::assertSame('--05-12', $parsed->birthday, 'a birthday without a year');
        self::assertSame([['label' => 'anniversary', 'date' => '2010-06-01'], ['label' => 'First met', 'date' => '2009-01-15']], $parsed->dates);
        self::assertSame([['label' => 'spouse', 'name' => 'Kenji'], ['label' => 'partner', 'name' => 'Sam'], ['label' => 'Godmother', 'name' => 'Mary']], $parsed->relations, 'domesticPartner is a partner; a custom type is kept');
        self::assertSame([['label' => 'home', 'url' => 'https://haru.example'], ['label' => 'blog', 'url' => 'https://blog.example']], $parsed->urls);
        self::assertSame([
            ['service' => 'xmpp', 'handle' => 'haru@jabber.example', 'label' => 'work'],
            ['service' => 'skype', 'handle' => 'haru.t', 'label' => 'other'],
            ['service' => 'sip', 'handle' => 'haru@sip.example', 'label' => 'mobile'],
        ], $parsed->ims, 'jabber is XMPP, and a SIP address a handle with the service sip');
        self::assertSame([['label' => 'Employee ID', 'value' => '4711']], $parsed->customFields);
        self::assertTrue($parsed->photoDeferred, 'the photo is read from its URL, by the driver');
        self::assertSame('https://lh3.googleusercontent.com/contact-photo=s100', $parsed->photoVersion, 'the URL, which changes with the photo');
    }

    public function testOnlyTheLetterAvatarOrAProfilePhotoIsNoPhoto(): void
    {
        $person           = $this->person();
        $person['photos'] = [
            ['url' => 'https://lh3.googleusercontent.com/letter', 'default' => true, 'metadata' => ['source' => ['type' => 'CONTACT']]],
            ['url' => 'https://lh3.googleusercontent.com/profile', 'metadata' => ['source' => ['type' => 'PROFILE']]],
        ];

        $parsed = new GoogleContactMapper()->toParsed($person, []);

        self::assertTrue($parsed->photoAbsent);
        self::assertFalse($parsed->photoDeferred);
    }

    public function testEveryFieldGoesBackOutInGooglesShape(): void
    {
        $contact = Contact::create(new User(), 'Haru Tanaka');
        $contact->namePrefix         = 'Dr.';
        $contact->givenName          = 'Haru';
        $contact->middleName         = 'K.';
        $contact->familyName         = 'Tanaka';
        $contact->phoneticGivenName  = 'ハル';
        $contact->company            = 'Analytical Engines';
        $contact->department         = 'Research';
        $contact->officeLocation     = 'Room 14';
        $contact->birthday           = '--05-12';
        $contact->dates              = [['label' => 'anniversary', 'date' => '2010-06-01']];
        $contact->relations          = [['label' => 'partner', 'name' => 'Sam'], ['label' => 'Godmother', 'name' => 'Mary']];
        $contact->urls               = [['label' => 'blog', 'url' => 'https://blog.example']];
        $contact->ims                = [['service' => 'xmpp', 'handle' => 'haru@jabber.example', 'label' => 'work'], ['service' => 'sip', 'handle' => 'haru@sip.example', 'label' => 'other']];
        $contact->customFields       = [['label' => 'Employee ID', 'value' => '4711']];

        $person = new GoogleContactMapper()->toPerson($contact, [], [
            ['name' => 'Old name', 'symbol' => 'AE', 'costCenter' => '42'],
            ['name' => 'Second employer', 'title' => 'Advisor'],
        ]);

        self::assertSame(['honorificPrefix' => 'Dr.', 'givenName' => 'Haru', 'middleName' => 'K.', 'familyName' => 'Tanaka', 'phoneticGivenName' => 'ハル'], $person['names'][0]);
        self::assertSame(['symbol' => 'AE', 'costCenter' => '42', 'name' => 'Analytical Engines', 'department' => 'Research', 'location' => 'Room 14'], $person['organizations'][0], 'what plMail does not edit of the organisation is kept');
        self::assertSame('Second employer', $person['organizations'][1]['name'], 'and the organisations after the first');
        self::assertSame([['date' => ['month' => 5, 'day' => 12]]], $person['birthdays']);
        self::assertSame([['date' => ['year' => 2010, 'month' => 6, 'day' => 1], 'type' => 'anniversary']], $person['events']);
        self::assertSame([['person' => 'Sam', 'type' => 'domesticPartner'], ['person' => 'Mary', 'type' => 'Godmother']], $person['relations']);
        self::assertSame([['value' => 'https://blog.example', 'type' => 'blog']], $person['urls']);
        self::assertSame([['username' => 'haru@jabber.example', 'type' => 'work', 'protocol' => 'jabber']], $person['imClients']);
        self::assertSame([['value' => 'haru@sip.example', 'type' => 'other']], $person['sipAddresses']);
        self::assertSame([['key' => 'Employee ID', 'value' => '4711']], $person['userDefined']);

        $back = new GoogleContactMapper()->toParsed(['names' => $person['names']] + $person, []);

        self::assertSame($contact->relations, $back->relations, 'what goes out reads back the same');
        self::assertSame($contact->ims, $back->ims);
        self::assertSame($contact->birthday, $back->birthday);
    }

    public function testTheOrganisationsOfTheLastReadAreKeptForTheNextWrite(): void
    {
        $person = $this->person();

        $resource = json_decode(new GoogleContactMapper()->resourceOf($person, []), true);

        self::assertSame([['name' => 'Analytical Engines', 'phoneticName' => 'アナリティカル', 'department' => 'Research', 'location' => 'Room 14', 'title' => 'Engineer', 'symbol' => 'AE']], $resource['organizations'], 'without the metadata Google will not take back');
    }

    /**
     * @return array<string, mixed>
     */
    private function person(): array
    {
        $contact = ['metadata' => ['source' => ['type' => 'CONTACT']]];

        return [
            'resourceName'  => 'people/c1',
            'etag'          => '"p"',
            'metadata'      => ['sources' => [['type' => 'CONTACT', 'id' => 'c1', 'etag' => '"src"']]],
            'names'         => [$contact + ['displayName' => 'Haru Tanaka', 'honorificPrefix' => 'Dr.', 'givenName' => 'Haru', 'middleName' => 'K.', 'familyName' => 'Tanaka', 'honorificSuffix' => 'PhD', 'phoneticGivenName' => 'ハル', 'phoneticFamilyName' => 'タナカ']],
            'organizations' => [$contact + ['name' => 'Analytical Engines', 'phoneticName' => 'アナリティカル', 'department' => 'Research', 'location' => 'Room 14', 'title' => 'Engineer', 'symbol' => 'AE', 'formattedType' => 'Work']],
            'birthdays'     => [$contact + ['date' => ['month' => 5, 'day' => 12]]],
            'events'        => [
                ['date' => ['year' => 2010, 'month' => 6, 'day' => 1], 'type' => 'anniversary', 'formattedType' => 'Anniversary'],
                ['date' => ['year' => 2009, 'month' => 1, 'day' => 15], 'type' => 'First met', 'formattedType' => 'First met'],
            ],
            'relations'     => [['person' => 'Kenji', 'type' => 'spouse'], ['person' => 'Sam', 'type' => 'domesticPartner'], ['person' => 'Mary', 'type' => 'Godmother']],
            'urls'          => [['value' => 'https://haru.example', 'type' => 'homePage'], ['value' => 'https://blog.example', 'type' => 'blog']],
            'imClients'     => [['username' => 'haru@jabber.example', 'type' => 'work', 'protocol' => 'jabber'], ['username' => 'haru.t', 'protocol' => 'skype']],
            'sipAddresses'  => [['value' => 'haru@sip.example', 'type' => 'mobile']],
            'userDefined'   => [['key' => 'Employee ID', 'value' => '4711']],
            'photos'        => [$contact + ['url' => 'https://lh3.googleusercontent.com/contact-photo=s100']],
        ];
    }
}
