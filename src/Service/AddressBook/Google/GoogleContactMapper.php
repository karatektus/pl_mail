<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Google;

use App\Entity\Contacts\Contact;
use App\Service\Contacts\ParsedContact;

/**
 * A People API Person to and from what plMail models — VCardMapper's job, for
 * Google's JSON.
 *
 * **Only the fields plMail shows go out**, and only those are named in
 * updatePersonFields (UPDATE_FIELDS), so Google keeps everything else on the
 * contact — birthdays, relations, its own custom fields — exactly as a CardDAV
 * push keeps the vCard properties plMail has no column for.
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
    public const string READ_FIELDS = 'names,nicknames,emailAddresses,phoneNumbers,addresses,organizations,biographies,memberships,metadata';

    /** What a push replaces; everything else on the contact is Google's to keep. */
    public const string UPDATE_FIELDS = 'names,nicknames,emailAddresses,phoneNumbers,addresses,organizations,biographies,memberships';

    private const array LABELS = ['home', 'work', 'mobile', 'other'];

    /**
     * @param array<string, mixed>  $person
     * @param array<string, string> $groupNames user groups, resourceName → name
     */
    public function toParsed(array $person, array $groupNames): ParsedContact
    {
        $parsed = new ParsedContact();

        $name                = $this->first($person, 'names');
        $parsed->displayName = $this->string($name, 'displayName') ?? $this->string($name, 'unstructuredName');
        $parsed->givenName   = $this->string($name, 'givenName');
        $parsed->familyName  = $this->string($name, 'familyName');
        $parsed->nickname    = $this->string($this->first($person, 'nicknames'), 'value');

        $organisation     = $this->first($person, 'organizations');
        $parsed->company  = $this->string($organisation, 'name');
        $parsed->jobTitle = $this->string($organisation, 'title');
        $parsed->notes    = $this->string($this->first($person, 'biographies'), 'value');

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
     * @param list<array<string, mixed>> $memberships what the contact should belong to
     *
     * @return array<string, mixed>
     */
    public function toPerson(Contact $contact, array $memberships): array
    {
        $given  = $this->clean($contact->givenName);
        $family = $this->clean($contact->familyName);

        // Names is a singleton. Structured when there is structure; the
        // display name alone otherwise, which Google keeps as typed.
        $name = null !== $given || null !== $family
            ? array_filter(['givenName' => $given, 'familyName' => $family], static fn (?string $v): bool => null !== $v)
            : ['unstructuredName' => $contact->displayName];

        $person = [
            'names'          => [$name],
            'nicknames'      => null === ($nick = $this->clean($contact->nickname)) ? [] : [['value' => $nick]],
            'organizations'  => null === $this->clean($contact->company) && null === $this->clean($contact->jobTitle)
                ? []
                : [array_filter(['name' => $this->clean($contact->company), 'title' => $this->clean($contact->jobTitle)], static fn (?string $v): bool => null !== $v)],
            'biographies'    => null === ($notes = $this->clean($contact->notes)) ? [] : [['value' => $notes, 'contentType' => 'TEXT_PLAIN']],
            'emailAddresses' => [],
            'phoneNumbers'   => [],
            'addresses'      => [],
            'memberships'    => $memberships,
        ];

        // Primary first: the People API reads the order, not a flag a client
        // may set.
        foreach ($this->primaryFirst($contact->emails->toArray()) as $email) {
            $person['emailAddresses'][] = ['value' => $email->email, 'type' => $this->googleType($email->label, false)];
        }

        foreach ($this->primaryFirst($contact->phones->toArray()) as $phone) {
            $person['phoneNumbers'][] = ['value' => $phone->phone, 'type' => $this->googleType($phone->label, true)];
        }

        foreach ($contact->addresses as $address) {
            $person['addresses'][] = array_filter([
                'type'          => $this->googleType($address->label, false),
                'streetAddress' => $this->clean($address->street),
                'city'          => $this->clean($address->city),
                'region'        => $this->clean($address->region),
                'postalCode'    => $this->clean($address->postcode),
                'country'       => $this->clean($address->country),
            ], static fn (?string $v): bool => null !== $v);
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

        return (string) json_encode([
            'sources'     => $this->contactSources($person),
            'memberships' => array_map(static fn (array $m): array => ['contactGroupMembership' => ['contactGroupResourceName' => $m['contactGroupMembership']['contactGroupResourceName'] ?? '']], $system),
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
