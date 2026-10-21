<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Graph;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Service\Contacts\ContactFields;
use App\Service\Contacts\ParsedContact;

/**
 * An Outlook contact (Graph `contact`) to and from what plMail models.
 *
 * **Outlook's contact has fixed places**, where plMail has lists:
 *
 *   - three email addresses, with no label — they come back as `other`;
 *   - business phones and home phones (two of each, as Outlook shows them),
 *     and one mobile;
 *   - one home, one business and one other address;
 *   - one spouse, one manager and one assistant, by name, and the children's
 *     names — the relations Outlook has places for;
 *   - one website (businessHomePage), the work one if there is one;
 *   - three chat addresses, with no service: `sip:` ones come back as SIP,
 *     the rest as `other`;
 *   - a birthday, and no other date;
 *   - phonetic given and family name and company (Outlook's yomi names),
 *     and no phonetic middle name.
 *
 * Custom fields have no place at all.
 * A push fills those places from the contact's rows, primary first, and what
 * does not fit is not sent — a fourth email address, a third work number.
 * It stays on the contact here until the contact next changes in Outlook,
 * when the pull brings back Outlook's version. The docs say so
 * (providers/microsoft.md); silently inventing a fourth slot is not possible.
 *
 * **Only these fields are sent**, so what plMail does not show (initials,
 * profession, file-as) is Outlook's to keep: a PATCH leaves every property it
 * does not name alone.
 *
 * The photo is not in the contact: GraphContactsDriver reads and sends it on
 * its own endpoint.
 *
 * Categories are tags.
 */
final readonly class GraphContactMapper
{
    /** What a pull reads. */
    public const string SELECT = 'id,displayName,title,givenName,middleName,surname,generation,yomiGivenName,yomiSurname,nickName,companyName,yomiCompanyName,department,jobTitle,officeLocation,personalNotes,birthday,spouseName,children,manager,assistantName,businessHomePage,imAddresses,categories,emailAddresses,businessPhones,homePhones,mobilePhone,homeAddress,businessAddress,otherAddress';

    private const int EMAILS = 3;
    private const int PHONES_PER_KIND = 2;
    private const int IM_ADDRESSES = 3;

    /** Outlook's single-name relations, field → plMail's label. */
    private const array RELATIONS = ['spouseName' => 'spouse', 'manager' => 'manager', 'assistantName' => 'assistant'];

    /**
     * @param array<string, mixed> $item
     */
    public function toParsed(array $item): ParsedContact
    {
        $parsed              = new ParsedContact();
        $parsed->displayName = $this->string($item, 'displayName');
        $parsed->namePrefix  = $this->string($item, 'title');
        $parsed->givenName   = $this->string($item, 'givenName');
        $parsed->middleName  = $this->string($item, 'middleName');
        $parsed->familyName  = $this->string($item, 'surname');
        $parsed->nameSuffix  = $this->string($item, 'generation');
        $parsed->nickname    = $this->string($item, 'nickName');
        $parsed->company     = $this->string($item, 'companyName');
        $parsed->department  = $this->string($item, 'department');
        $parsed->jobTitle    = $this->string($item, 'jobTitle');
        $parsed->notes       = $this->string($item, 'personalNotes');
        $parsed->tags        = $this->strings($item, 'categories');

        $parsed->phoneticGivenName  = $this->string($item, 'yomiGivenName');
        $parsed->phoneticFamilyName = $this->string($item, 'yomiSurname');
        $parsed->phoneticCompany    = $this->string($item, 'yomiCompanyName');
        $parsed->officeLocation     = $this->string($item, 'officeLocation');
        $parsed->birthday           = ContactFields::date($this->string($item, 'birthday'));

        $relations = [];

        foreach (self::RELATIONS as $field => $label) {
            $relations[] = ['label' => $label, 'name' => (string) $this->string($item, $field)];
        }

        foreach ($this->strings($item, 'children') as $child) {
            $relations[] = ['label' => 'child', 'name' => $child];
        }

        $parsed->relations = ContactFields::relations($relations);
        $parsed->urls      = ContactFields::urls([['label' => 'work', 'url' => (string) $this->string($item, 'businessHomePage')]]);

        $ims = [];

        foreach ($this->strings($item, 'imAddresses') as $address) {
            $ims[] = 1 === preg_match('/^sip:(.+)$/i', $address, $m)
                ? ['service' => 'sip', 'handle' => $m[1], 'label' => 'other']
                : ['service' => 'other', 'handle' => $address, 'label' => 'other'];
        }

        $parsed->ims = ContactFields::ims($ims);

        // Read on its own endpoint, every time the contact changed: a Graph
        // contact says nothing about its photo that would tell a new one.
        $parsed->photoDeferred = true;

        foreach ($this->list($item, 'emailAddresses') as $index => $email) {
            if (null !== ($address = $this->string($email, 'address'))) {
                $parsed->emails[] = ['email' => $address, 'label' => 'other', 'primary' => 0 === $index];
            }
        }

        foreach ($this->strings($item, 'businessPhones') as $phone) {
            $parsed->phones[] = ['phone' => $phone, 'label' => 'work'];
        }

        foreach ($this->strings($item, 'homePhones') as $phone) {
            $parsed->phones[] = ['phone' => $phone, 'label' => 'home'];
        }

        if (null !== ($mobile = $this->string($item, 'mobilePhone'))) {
            $parsed->phones[] = ['phone' => $mobile, 'label' => 'mobile'];
        }

        foreach (['homeAddress' => 'home', 'businessAddress' => 'work', 'otherAddress' => 'other'] as $field => $label) {
            $address = is_array($item[$field] ?? null) ? $item[$field] : [];
            $row     = [
                'label'    => $label,
                'street'   => $this->string($address, 'street'),
                'city'     => $this->string($address, 'city'),
                'region'   => $this->string($address, 'state'),
                'postcode' => $this->string($address, 'postalCode'),
                'country'  => $this->string($address, 'countryOrRegion'),
            ];

            if ([] !== array_filter([$row['street'], $row['city'], $row['region'], $row['postcode'], $row['country']])) {
                $parsed->addresses[] = $row;
            }
        }

        return $parsed;
    }

    /**
     * The body for a create or a PATCH. Every field it manages is present,
     * empty or not, so a value removed here is removed in Outlook.
     *
     * displayName always: Graph regenerates it from the parts on an update
     * that leaves it out, and would overwrite the one somebody typed.
     *
     * @return array<string, mixed>
     */
    public function toGraph(Contact $contact): array
    {
        /** @var list<ContactEmail> $emails */
        $emails = $this->primaryFirst($contact->emails->toArray());
        /** @var list<ContactPhone> $phones */
        $phones = $this->primaryFirst($contact->phones->toArray());

        $business = [];
        $home     = [];
        $mobile   = null;

        foreach ($phones as $phone) {
            match (true) {
                'mobile' === $phone->label && null === $mobile        => $mobile = $phone->phone,
                'home' === $phone->label                              => $home[] = $phone->phone,
                default                                               => $business[] = $phone->phone,
            };
        }

        $addresses = ['homeAddress' => null, 'businessAddress' => null, 'otherAddress' => null];

        /** @var ContactAddress $address */
        foreach ($contact->addresses as $address) {
            $field = match ($address->label) {
                'home'  => 'homeAddress',
                'work'  => 'businessAddress',
                default => 'otherAddress',
            };

            $addresses[$field] ??= array_filter([
                'street'          => $address->street,
                'city'            => $address->city,
                'state'           => $address->region,
                'postalCode'      => $address->postcode,
                'countryOrRegion' => $address->country,
            ], static fn (?string $v): bool => null !== $v && '' !== trim($v));
        }

        $relations = ['spouseName' => null, 'manager' => null, 'assistantName' => null];
        $children  = [];

        foreach ($contact->relations as $relation) {
            match ($relation['label']) {
                'spouse', 'partner' => $relations['spouseName'] ??= $relation['name'],
                'manager'           => $relations['manager'] ??= $relation['name'],
                'assistant'         => $relations['assistantName'] ??= $relation['name'],
                'child'             => $children[] = $relation['name'],
                default             => null,
            };
        }

        $urls = $contact->urls;
        usort($urls, static fn (array $a, array $b): int => (int) ('work' === $b['label']) <=> (int) ('work' === $a['label']));

        $ims = array_map(
            static fn (array $im): string => 'sip' === $im['service'] ? 'sip:' . preg_replace('/^sip:/i', '', $im['handle']) : $im['handle'],
            $contact->ims,
        );

        return [
            'displayName'      => $contact->displayName,
            'title'            => $contact->namePrefix,
            'givenName'        => $contact->givenName,
            'middleName'       => $contact->middleName,
            'surname'          => $contact->familyName,
            'generation'       => $contact->nameSuffix,
            'yomiGivenName'    => $contact->phoneticGivenName,
            'yomiSurname'      => $contact->phoneticFamilyName,
            'nickName'         => $contact->nickname,
            'companyName'      => $contact->company,
            'yomiCompanyName'  => $contact->phoneticCompany,
            'department'       => $contact->department,
            'jobTitle'         => $contact->jobTitle,
            'officeLocation'   => $contact->officeLocation,
            'personalNotes'    => $contact->notes,
            'birthday'         => $this->graphBirthday($contact->birthday),
            'spouseName'       => $relations['spouseName'],
            'manager'          => $relations['manager'],
            'assistantName'    => $relations['assistantName'],
            'children'         => $children,
            'businessHomePage' => $urls[0]['url'] ?? null,
            'imAddresses'      => array_slice($ims, 0, self::IM_ADDRESSES),
            'categories'     => array_values(array_unique($contact->tags)),
            'emailAddresses' => array_map(
                static fn (ContactEmail $e): array => ['address' => $e->email, 'name' => $contact->displayName],
                array_slice($emails, 0, self::EMAILS),
            ),
            'businessPhones' => array_slice($business, 0, self::PHONES_PER_KIND),
            'homePhones'     => array_slice($home, 0, self::PHONES_PER_KIND),
            'mobilePhone'    => $mobile,
            // A structured property is replaced whole; {} clears it.
            'homeAddress'     => $addresses['homeAddress'] ?? new \stdClass(),
            'businessAddress' => $addresses['businessAddress'] ?? new \stdClass(),
            'otherAddress'    => $addresses['otherAddress'] ?? new \stdClass(),
        ];
    }

    /**
     * Outlook's birthday is a timestamp. Noon UTC, as Outlook writes its own,
     * so no time zone shows it on the day before; 1604 for no year, as
     * Outlook does.
     */
    private function graphBirthday(?string $birthday): ?string
    {
        if (null === $birthday) {
            return null;
        }

        $parts = ContactFields::dateParts($birthday);

        return sprintf('%04d-%02d-%02dT11:59:00Z', $parts['year'] ?? 1604, $parts['month'], $parts['day']);
    }

    /** @param array<string, mixed> $item */
    public function etagOf(array $item): ?string
    {
        return is_string($item['@odata.etag'] ?? null) ? $item['@odata.etag'] : (is_string($item['changeKey'] ?? null) ? $item['changeKey'] : null);
    }

    /**
     * @template T of ContactEmail|ContactPhone
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function primaryFirst(array $items): array
    {
        usort($items, static fn (ContactEmail|ContactPhone $a, ContactEmail|ContactPhone $b): int => (int) $b->isPrimary <=> (int) $a->isPrimary);

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<array<string, mixed>>
     */
    private function list(array $item, string $field): array
    {
        $values = $item[$field] ?? [];

        return array_values(array_filter(is_array($values) ? $values : [], is_array(...)));
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<string>
     */
    private function strings(array $item, string $field): array
    {
        $values = $item[$field] ?? [];

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', is_array($values) ? $values : []),
            static fn (string $v): bool => '' !== $v,
        ));
    }

    /** @param array<string, mixed> $item */
    private function string(array $item, string $key): ?string
    {
        $value = is_string($item[$key] ?? null) ? trim($item[$key]) : '';

        return '' === $value ? null : $value;
    }
}
