<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Google;

use App\Entity\Contacts\Contact;
use App\Service\Contacts\ContactFields;
use App\Service\Contacts\ParsedContact;

/**
 * A People API Person to and from what plMail models — VCardMapper's job, for
 * Google's JSON.
 *
 * **Only the fields plMail shows go out**, and only those are named in
 * updatePersonFields (UPDATE_FIELDS), so Google keeps everything else on the
 * contact — occupations, interests, external ids, a gender — exactly as a
 * CardDAV push keeps the vCard properties plMail has no column for.
 *
 * Within a field that does go out, what plMail does not model is carried over
 * from the last read (resourceOf()): an organisation's symbol or cost centre,
 * and any organisation after the first. A Person may hold several names and
 * organisations; plMail edits the first.
 *
 * **Chat handles** are Google's imClients, and a SIP address (sipAddresses)
 * is a handle with the service `sip`. **Dates** are birthdays (one, the
 * contact's own) and events. Custom fields are userDefined.
 *
 * **The photo** is not part of a Person write: GoogleContactsDriver sends it
 * with updateContactPhoto. Read, it is the contact's own photo — not the
 * letter avatar Google draws for one without (`default: true`), nor the
 * person's profile picture, which only they can change.
 *
 * **Tags are contact groups.** A user's own groups (USER_CONTACT_GROUP) become
 * tags by name, and tags become memberships of the groups of those names. The
 * system groups a contact is in (My Contacts, Starred) are not tags and are
 * kept as they were — see GoogleContactsDriver.
 *
 * Labels map onto plMail's four (home, work, mobile, other); a custom label
 * Google shows comes back as `other`.
 */
final readonly class GoogleContactMapper
{
    /** What a pull asks for. */
    public const string READ_FIELDS = 'names,nicknames,emailAddresses,phoneNumbers,addresses,organizations,biographies,memberships,birthdays,events,relations,urls,imClients,sipAddresses,userDefined,photos,metadata';

    /** What a push replaces; everything else on the contact is Google's to keep. */
    public const string UPDATE_FIELDS = 'names,nicknames,emailAddresses,phoneNumbers,addresses,organizations,biographies,memberships,birthdays,events,relations,urls,imClients,sipAddresses,userDefined';

    private const array LABELS = ['home', 'work', 'mobile', 'other'];

    /** Google's relation types that are a different word for one of plMail's. */
    private const array RELATION_ALIASES = ['domesticPartner' => 'partner'];

    /** Google's imClient protocols that plMail names differently. */
    private const array PROTOCOL_ALIASES = ['jabber' => 'xmpp'];

    /** What Google keeps of an organisation that plMail neither shows nor edits. */
    private const array ORGANIZATION_KEPT = ['type', 'startDate', 'endDate', 'current', 'symbol', 'domain', 'jobDescription', 'costCenter', 'fullTimeEquivalentMillipercent'];

    /**
     * @param array<string, mixed>  $person
     * @param array<string, string> $groupNames user groups, resourceName → name
     */
    public function toParsed(array $person, array $groupNames): ParsedContact
    {
        $parsed = new ParsedContact();

        $name                       = $this->first($person, 'names');
        $parsed->displayName        = $this->string($name, 'displayName') ?? $this->string($name, 'unstructuredName');
        $parsed->namePrefix         = $this->string($name, 'honorificPrefix');
        $parsed->givenName          = $this->string($name, 'givenName');
        $parsed->middleName         = $this->string($name, 'middleName');
        $parsed->familyName         = $this->string($name, 'familyName');
        $parsed->nameSuffix         = $this->string($name, 'honorificSuffix');
        $parsed->phoneticGivenName  = $this->string($name, 'phoneticGivenName');
        $parsed->phoneticMiddleName = $this->string($name, 'phoneticMiddleName');
        $parsed->phoneticFamilyName = $this->string($name, 'phoneticFamilyName');
        $parsed->nickname           = $this->string($this->first($person, 'nicknames'), 'value');

        $organisation            = $this->first($person, 'organizations');
        $parsed->company         = $this->string($organisation, 'name');
        $parsed->phoneticCompany = $this->string($organisation, 'phoneticName');
        $parsed->department      = $this->string($organisation, 'department');
        $parsed->jobTitle        = $this->string($organisation, 'title');
        $parsed->officeLocation  = $this->string($organisation, 'location');
        $parsed->notes           = $this->string($this->first($person, 'biographies'), 'value');
        $parsed->birthday        = $this->dateOf($this->first($person, 'birthdays'));

        $dates = [];

        foreach ($this->list($person, 'events') as $event) {
            $dates[] = ['label' => $this->ownLabel($event, ContactFields::DATE_LABELS, []), 'date' => (string) $this->dateOf($event)];
        }

        $parsed->dates = ContactFields::dates($dates);

        $relations = [];

        foreach ($this->list($person, 'relations') as $relation) {
            $relations[] = ['label' => $this->ownLabel($relation, ContactFields::RELATION_LABELS, self::RELATION_ALIASES), 'name' => (string) $this->string($relation, 'person')];
        }

        $parsed->relations = ContactFields::relations($relations);

        $urls = [];

        foreach ($this->list($person, 'urls') as $url) {
            $urls[] = ['label' => $this->ownLabel($url, ContactFields::URL_LABELS, ['homePage' => 'home']), 'url' => (string) $this->string($url, 'value')];
        }

        $parsed->urls = ContactFields::urls($urls);

        $ims = [];

        foreach ($this->list($person, 'imClients') as $im) {
            $protocol = (string) ($this->string($im, 'protocol') ?? 'other');
            $ims[]    = [
                'service' => self::PROTOCOL_ALIASES[$protocol] ?? $protocol,
                'handle'  => (string) $this->string($im, 'username'),
                'label'   => $this->ownLabel($im, ContactFields::IM_LABELS, []),
            ];
        }

        foreach ($this->list($person, 'sipAddresses') as $sip) {
            $ims[] = ['service' => 'sip', 'handle' => (string) $this->string($sip, 'value'), 'label' => $this->ownLabel($sip, [...ContactFields::IM_LABELS, 'mobile'], [])];
        }

        $parsed->ims = ContactFields::ims($ims);

        $custom = [];

        foreach ($this->list($person, 'userDefined') as $field) {
            $custom[] = ['label' => (string) $this->string($field, 'key'), 'value' => (string) $this->string($field, 'value')];
        }

        $parsed->customFields = ContactFields::customFields($custom);

        $photo = $this->photoOf($person);

        if (null === $photo) {
            $parsed->photoAbsent = true;
        } else {
            $parsed->photoDeferred = true;
            $parsed->photoVersion  = $photo;
        }

        foreach ($this->list($person, 'emailAddresses') as $email) {
            if (null !== ($value = $this->string($email, 'value'))) {
                $parsed->emails[] = ['email' => $value, 'label' => $this->label($email, false), 'primary' => $this->isPrimary($email)];
            }
        }

        foreach ($this->list($person, 'phoneNumbers') as $phone) {
            if (null !== ($value = $this->string($phone, 'value'))) {
                $parsed->phones[] = ['phone' => $value, 'label' => $this->label($phone, true), 'primary' => $this->isPrimary($phone)];
            }
        }

        foreach ($this->list($person, 'addresses') as $address) {
            $parsed->addresses[] = [
                'label'    => $this->label($address, false),
                'street'   => $this->string($address, 'streetAddress'),
                'city'     => $this->string($address, 'city'),
                'region'   => $this->string($address, 'region'),
                'postcode' => $this->string($address, 'postalCode'),
                'country'  => $this->string($address, 'country'),
            ];
        }

        foreach ($this->list($person, 'memberships') as $membership) {
            $group = $membership['contactGroupMembership']['contactGroupResourceName'] ?? null;

            if (true === is_string($group) && true === isset($groupNames[$group])) {
                $parsed->tags[] = $groupNames[$group];
            }
        }

        return $parsed;
    }

    /**
     * The fields of UPDATE_FIELDS, from the contact's rows. Every field is
     * present, empty or not, so a value removed here is removed at Google.
     *
     * @param list<array<string, mixed>> $memberships   what the contact should belong to
     * @param list<array<string, mixed>> $organizations the organisations of the last read (resourceOf())
     *
     * @return array<string, mixed>
     */
    public function toPerson(Contact $contact, array $memberships, array $organizations = []): array
    {
        // Names is a singleton. Structured when there is structure; the
        // display name alone otherwise, which Google keeps as typed.
        $name = $this->filled([
            'honorificPrefix'    => $contact->namePrefix,
            'givenName'          => $contact->givenName,
            'middleName'         => $contact->middleName,
            'familyName'         => $contact->familyName,
            'honorificSuffix'    => $contact->nameSuffix,
            'phoneticGivenName'  => $contact->phoneticGivenName,
            'phoneticMiddleName' => $contact->phoneticMiddleName,
            'phoneticFamilyName' => $contact->phoneticFamilyName,
        ]);

        if (null === $this->clean($contact->givenName) && null === $this->clean($contact->familyName) && null === $this->clean($contact->middleName)) {
            $name['unstructuredName'] = $contact->displayName;
        }

        $organisation = $this->filled([
            'name'         => $contact->company,
            'phoneticName' => $contact->phoneticCompany,
            'department'   => $contact->department,
            'title'        => $contact->jobTitle,
            'location'     => $contact->officeLocation,
        ]);

        $kept = array_intersect_key($organizations[0] ?? [], array_flip(self::ORGANIZATION_KEPT));

        $person = [
            'names'          => [$name],
            'nicknames'      => null === ($nick = $this->clean($contact->nickname)) ? [] : [['value' => $nick]],
            'organizations'  => [...([] === $organisation && [] === $kept ? [] : [$kept + $organisation]), ...array_slice($organizations, 1)],
            'biographies'    => null === ($notes = $this->clean($contact->notes)) ? [] : [['value' => $notes, 'contentType' => 'TEXT_PLAIN']],
            'birthdays'      => null === $contact->birthday ? [] : [['date' => $this->googleDate($contact->birthday)]],
            'events'         => [],
            'relations'      => [],
            'urls'           => [],
            'imClients'      => [],
            'sipAddresses'   => [],
            'userDefined'    => [],
            'emailAddresses' => [],
            'phoneNumbers'   => [],
            'addresses'      => [],
            'memberships'    => $memberships,
        ];

        foreach ($contact->dates as $date) {
            $person['events'][] = ['date' => $this->googleDate($date['date']), 'type' => $date['label']];
        }

        foreach ($contact->relations as $relation) {
            $person['relations'][] = ['person' => $relation['name'], 'type' => array_search($relation['label'], self::RELATION_ALIASES, true) ?: $relation['label']];
        }

        foreach ($contact->urls as $url) {
            $person['urls'][] = ['value' => $url['url'], 'type' => $url['label']];
        }

        foreach ($contact->ims as $im) {
            if ('sip' === $im['service']) {
                $person['sipAddresses'][] = ['value' => $im['handle'], 'type' => $im['label']];

                continue;
            }

            $person['imClients'][] = [
                'username' => $im['handle'],
                'type'     => $im['label'],
                'protocol' => array_search($im['service'], self::PROTOCOL_ALIASES, true) ?: $im['service'],
            ];
        }

        foreach ($contact->customFields as $field) {
            $person['userDefined'][] = ['key' => $field['label'], 'value' => $field['value']];
        }

        // Primary first: the People API reads the order, not a flag a client
        // may set.
        foreach ($this->primaryFirst($contact->emails->toArray()) as $email) {
            $person['emailAddresses'][] = ['value' => $email->email, 'type' => $this->googleType($email->label, false)];
        }

        foreach ($this->primaryFirst($contact->phones->toArray()) as $phone) {
            $person['phoneNumbers'][] = ['value' => $phone->phone, 'type' => $this->googleType($phone->label, true)];
        }

        foreach ($contact->addresses as $address) {
            $person['addresses'][] = $this->filled([
                'type'          => $this->googleType($address->label, false),
                'streetAddress' => $address->street,
                'city'          => $address->city,
                'region'        => $address->region,
                'postalCode'    => $address->postcode,
                'country'       => $address->country,
            ]);
        }

        return $person;
    }

    /**
     * The CONTACT source's etag — the one updateContact checks — or the
     * person's own when the sources are absent.
     *
     * @param array<string, mixed> $person
     */
    public function etagOf(array $person): ?string
    {
        foreach ($this->contactSources($person) as $source) {
            if (true === is_string($source['etag'] ?? null)) {
                return $source['etag'];
            }
        }

        return is_string($person['etag'] ?? null) ? $person['etag'] : null;
    }

    /**
     * What a later updateContact must send back: the CONTACT sources, and
     * the memberships that are not tags.
     *
     * @param array<string, mixed>  $person
     * @param array<string, string> $groupNames user groups
     */
    public function resourceOf(array $person, array $groupNames): string
    {
        $system = array_values(array_filter(
            $this->list($person, 'memberships'),
            static fn (array $m): bool => false === isset($groupNames[(string) ($m['contactGroupMembership']['contactGroupResourceName'] ?? '')]),
        ));

        // The contact's own organisations, without Google's read-only metadata.
        $organizations = array_values(array_map(
            static fn (array $o): array => array_diff_key($o, ['metadata' => true, 'formattedType' => true]),
            array_filter($this->list($person, 'organizations'), static fn (array $o): bool => 'CONTACT' === ($o['metadata']['source']['type'] ?? 'CONTACT')),
        ));

        return (string) json_encode([
            'sources'       => $this->contactSources($person),
            'memberships'   => array_map(static fn (array $m): array => ['contactGroupMembership' => ['contactGroupResourceName' => $m['contactGroupMembership']['contactGroupResourceName'] ?? '']], $system),
            'organizations' => $organizations,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $person
     *
     * @return list<array<string, mixed>>
     */
    private function contactSources(array $person): array
    {
        $sources = $person['metadata']['sources'] ?? [];

        return array_values(array_filter(
            is_array($sources) ? $sources : [],
            static fn (mixed $s): bool => is_array($s) && 'CONTACT' === ($s['type'] ?? null),
        ));
    }

    /**
     * The URL of the contact's own photo; null when it has none — only the
     * letter avatar, or a profile photo that is the person's, not the
     * contact's.
     *
     * @param array<string, mixed> $person
     */
    private function photoOf(array $person): ?string
    {
        foreach ($this->list($person, 'photos') as $photo) {
            if (true !== ($photo['default'] ?? false)
                && 'CONTACT' === ($photo['metadata']['source']['type'] ?? null)
                && null !== ($url = $this->string($photo, 'url'))) {
                return $url;
            }
        }

        return null;
    }

    /**
     * A Google Date (year optional), or its free-text form, as ContactFields
     * spells dates.
     *
     * @param array<string, mixed> $field
     */
    private function dateOf(array $field): ?string
    {
        $date = is_array($field['date'] ?? null) ? $field['date'] : [];

        return ContactFields::dateOf(
            is_int($date['year'] ?? null) ? $date['year'] : null,
            is_int($date['month'] ?? null) ? $date['month'] : null,
            is_int($date['day'] ?? null) ? $date['day'] : null,
        ) ?? ContactFields::date($this->string($field, 'text'));
    }

    /** @return array{year?: int, month: int, day: int} */
    private function googleDate(string $date): array
    {
        $parts = ContactFields::dateParts($date);

        return null === $parts['year']
            ? ['month' => $parts['month'], 'day' => $parts['day']]
            : ['year' => $parts['year'], 'month' => $parts['month'], 'day' => $parts['day']];
    }

    /**
     * The field's type as one of plMail's labels, through $aliases; a custom
     * type — which Google returns as typed — as it is.
     *
     * @param array<string, mixed>  $field
     * @param list<string>          $labels
     * @param array<string, string> $aliases
     */
    private function ownLabel(array $field, array $labels, array $aliases): string
    {
        $type = (string) $this->string($field, 'type');

        if (true === isset($aliases[$type])) {
            return $aliases[$type];
        }

        return '' === $type ? 'other' : (true === in_array(strtolower($type), $labels, true) ? strtolower($type) : $type);
    }

    /**
     * The entries whose value is not empty, trimmed.
     *
     * @param array<string, ?string> $fields
     *
     * @return array<string, string>
     */
    private function filled(array $fields): array
    {
        return array_filter(array_map($this->clean(...), $fields), static fn (?string $v): bool => null !== $v);
    }

    /**
     * @template T of object
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function primaryFirst(array $items): array
    {
        usort($items, static fn (object $a, object $b): int => (int) ($b->isPrimary ?? false) <=> (int) ($a->isPrimary ?? false));

        return $items;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function label(array $field, bool $isPhone): string
    {
        $type = strtolower((string) ($field['type'] ?? ''));

        if (true === $isPhone && in_array($type, ['mobile', 'main'], true)) {
            return 'mobile';
        }

        if (str_starts_with($type, 'work')) {
            return 'work';
        }

        if (str_starts_with($type, 'home')) {
            return 'home';
        }

        return in_array($type, self::LABELS, true) ? $type : 'other';
    }

    private function googleType(string $label, bool $isPhone): string
    {
        return match ($label) {
            'home', 'work' => $label,
            'mobile'       => true === $isPhone ? 'mobile' : 'other',
            default        => 'other',
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isPrimary(array $field): bool
    {
        return true === ($field['metadata']['primary'] ?? false);
    }

    /**
     * The value from the contact's own source, falling back to the first.
     * Google can carry a field twice — once from the contact, once from the
     * person's profile — and the contact's is the one a user edited.
     *
     * @param array<string, mixed> $person
     *
     * @return array<string, mixed>
     */
    private function first(array $person, string $field): array
    {
        $values = $this->list($person, $field);

        foreach ($values as $value) {
            if ('CONTACT' === ($value['metadata']['source']['type'] ?? null)) {
                return $value;
            }
        }

        return $values[0] ?? [];
    }

    /**
     * @param array<string, mixed> $person
     *
     * @return list<array<string, mixed>>
     */
    private function list(array $person, string $field): array
    {
        $values = $person[$field] ?? [];

        return array_values(array_filter(is_array($values) ? $values : [], is_array(...)));
    }

    /**
     * @param array<string, mixed> $field
     */
    private function string(array $field, string $key): ?string
    {
        return $this->clean(is_string($field[$key] ?? null) ? $field[$key] : null);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
