<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Jmap;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Service\Contacts\ContactFields;
use App\Service\Contacts\ParsedContact;

/**
 * JSContact cards (RFC 9553), as JMAP for Contacts (RFC 9610) carries them,
 * to and from plMail's contact model.
 *
 * The Google and Graph drivers translate their providers' JSON into
 * ParsedContact at their own boundary, and this is the same translation for
 * the IETF's JSON: everything above the driver — the merge, the photo store,
 * harvesting — reads a ParsedContact and never JSContact.
 *
 * ## The shape of a card
 *
 * Every multi-valued property is a map from an id the client chooses to an
 * object: `emails: {e1: {address, contexts: {work: true}}}`. Read here in the
 * server's order with `pref` first, written back with fresh ids — the ids
 * mean nothing to anyone, and keeping them would need a column for each list.
 * A context is plMail's label: `work` is work, `private` is home, and anything
 * else is other. Phones say mobile by `features`, not by context.
 *
 * ## What is written
 *
 * The properties plMail models, every one of them present, empty or not, so a
 * value removed here is removed there — the rule GraphContactMapper keeps.
 * Properties plMail does not model (cryptoKeys, preferredLanguages, a vendor's
 * own) are never named and so stay as the server has them: an update replaces
 * only what it names.
 */
final class JsContactMapper
{
    /** The properties toCard() always writes. See the class docblock. */
    public const array MANAGED = [
        'name', 'nicknames', 'organizations', 'titles', 'emails', 'phones', 'addresses',
        'onlineServices', 'links', 'anniversaries', 'notes', 'keywords', 'relatedTo',
    ];

    /**
     * @param array<string, mixed> $card
     */
    public function toParsed(array $card): ParsedContact
    {
        $parsed      = new ParsedContact();
        $parsed->uid = self::string($card, 'uid');

        $name = is_array($card['name'] ?? null) ? $card['name'] : [];

        foreach (is_array($name['components'] ?? null) ? $name['components'] : [] as $component) {
            $value = is_array($component) ? self::string($component, 'value') : null;

            if (null === $value) {
                continue;
            }

            $phonetic = self::string($component, 'phonetic');

            match ((string) ($component['kind'] ?? '')) {
                'title'      => $parsed->namePrefix ??= $value,
                'given'      => [$parsed->givenName ??= $value, $parsed->phoneticGivenName ??= $phonetic],
                'given2'     => [$parsed->middleName ??= $value, $parsed->phoneticMiddleName ??= $phonetic],
                'surname'    => [$parsed->familyName ??= $value, $parsed->phoneticFamilyName ??= $phonetic],
                'credential', 'generation' => $parsed->nameSuffix ??= $value,
                default      => null,
            };
        }

        // `name.full` in RFC 9553; `fullName` on the card in the drafts a
        // server may still speak.
        $parsed->displayName = self::string($name, 'full') ?? self::string($card, 'fullName');

        foreach (self::entries($card, 'nicknames') as $nickname) {
            $parsed->nickname ??= self::string($nickname, 'name');
        }

        foreach (self::entries($card, 'organizations') as $organization) {
            $parsed->company ??= self::string($organization, 'name');

            foreach (is_array($organization['units'] ?? null) ? $organization['units'] : [] as $unit) {
                $parsed->department ??= is_array($unit) ? self::string($unit, 'name') : null;
            }
        }

        foreach (self::entries($card, 'titles') as $title) {
            $parsed->jobTitle ??= self::string($title, 'name');
        }

        foreach (self::entries($card, 'emails') as $index => $email) {
            $address = self::string($email, 'address');

            if (null !== $address) {
                $parsed->emails[] = ['email' => $address, 'label' => self::label($email), 'primary' => 0 === $index];
            }
        }

        foreach (self::entries($card, 'phones') as $index => $phone) {
            $number = self::string($phone, 'number');

            if (null !== $number) {
                $mobile           = true === ($phone['features']['mobile'] ?? false);
                $parsed->phones[] = ['phone' => $number, 'label' => $mobile ? 'mobile' : self::label($phone), 'primary' => 0 === $index];
            }
        }

        foreach (self::entries($card, 'addresses') as $address) {
            $row = ['label' => self::label($address), 'street' => null, 'city' => null, 'region' => null, 'postcode' => null, 'country' => null];

            foreach (is_array($address['components'] ?? null) ? $address['components'] : [] as $component) {
                $value = is_array($component) ? self::string($component, 'value') : null;
                $slot  = match ((string) ($component['kind'] ?? '')) {
                    'name', 'number', 'building', 'apartment' => 'street',
                    'locality'                                 => 'city',
                    'region'                                   => 'region',
                    'postcode'                                 => 'postcode',
                    'country'                                  => 'country',
                    default                                    => null,
                };

                if (null !== $value && null !== $slot) {
                    $row[$slot] = null === $row[$slot] ? $value : $row[$slot] . ' ' . $value;
                }
            }

            if ([] !== array_filter([$row['street'], $row['city'], $row['region'], $row['postcode'], $row['country']])) {
                $parsed->addresses[] = $row;
            }
        }

        $ims  = [];
        $urls = [];

        foreach (self::entries($card, 'onlineServices') as $service) {
            $handle = self::string($service, 'user') ?? self::string($service, 'uri');

            if (null !== $handle) {
                $ims[] = ['service' => strtolower(self::string($service, 'service') ?? 'other'), 'handle' => $handle, 'label' => self::label($service)];
            }
        }

        foreach (self::entries($card, 'links') as $link) {
            $uri = self::string($link, 'uri');

            if (null !== $uri) {
                $urls[] = ['label' => self::label($link), 'url' => $uri];
            }
        }

        $parsed->ims  = ContactFields::ims($ims);
        $parsed->urls = ContactFields::urls($urls);

        $dates = [];

        foreach (self::entries($card, 'anniversaries') as $anniversary) {
            $date = self::date($anniversary['date'] ?? null);

            if (null === $date) {
                continue;
            }

            if ('birth' === ($anniversary['kind'] ?? null)) {
                $parsed->birthday ??= $date;
            } else {
                $dates[] = ['label' => 'wedding' === ($anniversary['kind'] ?? null) ? 'anniversary' : 'other', 'date' => $date];
            }
        }

        $parsed->dates = ContactFields::dates($dates);

        $notes = [];

        foreach (self::entries($card, 'notes') as $note) {
            $text = self::string($note, 'note');

            if (null !== $text) {
                $notes[] = $text;
            }
        }

        $parsed->notes = [] === $notes ? null : implode("\n\n", $notes);

        $relations = [];

        foreach (is_array($card['relatedTo'] ?? null) ? $card['relatedTo'] : [] as $who => $relation) {
            $kinds = is_array($relation) && is_array($relation['relation'] ?? null) ? array_keys(array_filter($relation['relation'])) : [];

            $relations[] = ['label' => (string) ($kinds[0] ?? 'other'), 'name' => (string) $who];
        }

        $parsed->relations = ContactFields::relations($relations);
        $parsed->tags      = array_values(array_map(strval(...), array_keys(array_filter(is_array($card['keywords'] ?? null) ? $card['keywords'] : []))));

        // A photo is a media entry with a blob or a data: URI; the driver
        // reads its bytes with photo(), and only when there is one. No photo
        // entry is the card saying there is none.
        $parsed->photoDeferred = null !== $this->photoOf($card);
        $parsed->photoAbsent   = false === $parsed->photoDeferred;

        return $parsed;
    }

    /**
     * The managed properties of a card, from a stored contact.
     *
     * @return array<string, mixed>
     */
    public function toCard(Contact $contact): array
    {
        $components = [];

        foreach ([
            'title'      => $contact->namePrefix,
            'given'      => $contact->givenName,
            'given2'     => $contact->middleName,
            'surname'    => $contact->familyName,
            'credential' => $contact->nameSuffix,
        ] as $kind => $value) {
            if (null !== $value && '' !== trim($value)) {
                $component = ['kind' => $kind, 'value' => $value];
                $phonetic  = match ($kind) {
                    'given'   => $contact->phoneticGivenName,
                    'given2'  => $contact->phoneticMiddleName,
                    'surname' => $contact->phoneticFamilyName,
                    default   => null,
                };

                if (null !== $phonetic && '' !== trim($phonetic)) {
                    $component['phonetic'] = $phonetic;
                }

                $components[] = $component;
            }
        }

        $emails = [];

        /** @var ContactEmail $email */
        foreach ($contact->emails as $index => $email) {
            $emails['e' . $index] = ['address' => $email->email] + self::contexts($email->label) + (true === $email->isPrimary ? ['pref' => 1] : []);
        }

        $phones = [];

        /** @var ContactPhone $phone */
        foreach ($contact->phones as $index => $phone) {
            $phones['p' . $index] = ['number' => $phone->phone]
                + ('mobile' === $phone->label ? ['features' => ['mobile' => true]] : self::contexts($phone->label))
                + (true === $phone->isPrimary ? ['pref' => 1] : []);
        }

        $addresses = [];

        /** @var ContactAddress $address */
        foreach ($contact->addresses as $index => $address) {
            $parts = array_filter([
                ['kind' => 'name', 'value' => $address->street],
                ['kind' => 'locality', 'value' => $address->city],
                ['kind' => 'region', 'value' => $address->region],
                ['kind' => 'postcode', 'value' => $address->postcode],
                ['kind' => 'country', 'value' => $address->country],
            ], static fn (array $part): bool => null !== $part['value'] && '' !== trim((string) $part['value']));

            if ([] !== $parts) {
                $addresses['a' . $index] = ['components' => array_values($parts)] + self::contexts($address->label);
            }
        }

        $anniversaries = [];

        if (null !== $contact->birthday) {
            $anniversaries['birth'] = ['kind' => 'birth', 'date' => self::partialDate($contact->birthday)];
        }

        foreach ($contact->dates as $index => $date) {
            $anniversaries['d' . $index] = ['kind' => 'anniversary' === $date['label'] ? 'wedding' : 'other', 'date' => self::partialDate($date['date'])];
        }

        $relatedTo = [];

        foreach ($contact->relations as $relation) {
            $relatedTo[$relation['name']] = ['relation' => [$relation['label'] => true]];
        }

        // A stored contact always has a display name, but it can be blank —
        // one made from an address alone — and a blank `full` is noise.
        $name = array_filter([
            'components' => $components,
            'full'       => '' === trim($contact->displayName) ? null : $contact->displayName,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);

        return [
            'name'           => [] === $name ? null : $name,
            'nicknames'      => null === $contact->nickname ? null : ['n' => ['name' => $contact->nickname]],
            'organizations'  => null === $contact->company && null === $contact->department ? null : ['o' => array_filter([
                'name'  => $contact->company,
                'units' => null === $contact->department ? null : [['name' => $contact->department]],
            ], static fn (mixed $value): bool => null !== $value)],
            'titles'         => null === $contact->jobTitle ? null : ['t' => ['name' => $contact->jobTitle, 'kind' => 'title']],
            'emails'         => [] === $emails ? null : $emails,
            'phones'         => [] === $phones ? null : $phones,
            'addresses'      => [] === $addresses ? null : $addresses,
            'onlineServices' => [] === $contact->ims ? null : array_combine(
                array_map(static fn (int $i): string => 's' . $i, array_keys($contact->ims)),
                array_map(static fn (array $im): array => ['service' => $im['service'], 'user' => $im['handle']] + self::contexts($im['label']), $contact->ims),
            ),
            'links'          => [] === $contact->urls ? null : array_combine(
                array_map(static fn (int $i): string => 'l' . $i, array_keys($contact->urls)),
                array_map(static fn (array $url): array => ['uri' => $url['url']] + self::contexts($url['label']), $contact->urls),
            ),
            'anniversaries'  => [] === $anniversaries ? null : $anniversaries,
            'notes'          => null === $contact->notes || '' === trim($contact->notes) ? null : ['n' => ['note' => $contact->notes]],
            'keywords'       => [] === $contact->tags ? null : array_fill_keys(array_values(array_unique($contact->tags)), true),
            'relatedTo'      => [] === $relatedTo ? null : $relatedTo,
        ];
    }

    /**
     * Where a card's photo is: a blob id, or a data: URI. Null for none.
     *
     * @param array<string, mixed> $card
     *
     * @return array{blobId: string|null, uri: string|null, mediaType: string|null}|null
     */
    public function photoOf(array $card): ?array
    {
        foreach (self::entries($card, 'media') as $media) {
            if ('photo' !== ($media['kind'] ?? null)) {
                continue;
            }

            $blobId = self::string($media, 'blobId');
            $uri    = self::string($media, 'uri');

            if (null !== $blobId || null !== $uri) {
                return ['blobId' => $blobId, 'uri' => $uri, 'mediaType' => self::string($media, 'mediaType')];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A map property's objects, preferred first, then in the server's order.
     *
     * @param array<string, mixed> $card
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $card, string $property): array
    {
        $entries = array_values(array_filter(is_array($card[$property] ?? null) ? $card[$property] : [], 'is_array'));

        usort($entries, static fn (array $a, array $b): int => (int) ($a['pref'] ?? 101) <=> (int) ($b['pref'] ?? 101));

        return $entries;
    }

    /**
     * plMail's label for an entry, from its contexts.
     *
     * @param array<string, mixed> $entry
     */
    private static function label(array $entry): string
    {
        $contexts = is_array($entry['contexts'] ?? null) ? $entry['contexts'] : [];

        return match (true) {
            true === ($contexts['work'] ?? false)    => 'work',
            true === ($contexts['private'] ?? false) => 'home',
            default                                  => 'other',
        };
    }

    /**
     * The contexts an entry is written with, from plMail's label.
     *
     * @return array{contexts?: array<string, true>}
     */
    private static function contexts(string $label): array
    {
        return match ($label) {
            'work'  => ['contexts' => ['work' => true]],
            'home'  => ['contexts' => ['private' => true]],
            default => [],
        };
    }

    /**
     * A JSContact date — a PartialDate object or a UTC Timestamp — as
     * `YYYY-MM-DD`, or `--MM-DD` for one without a year (see ContactDate).
     */
    private static function date(mixed $date): ?string
    {
        if (true === is_string($date)) {
            return ContactFields::date(substr($date, 0, 10));
        }

        if (false === is_array($date)) {
            return null;
        }

        if ('Timestamp' === ($date['@type'] ?? null) && true === is_string($date['utc'] ?? null)) {
            return ContactFields::date(substr($date['utc'], 0, 10));
        }

        $month = (int) ($date['month'] ?? 0);
        $day   = (int) ($date['day'] ?? 0);

        if (0 === $month || 0 === $day) {
            return null;
        }

        $year = (int) ($date['year'] ?? 0);

        return ContactFields::date(0 === $year ? sprintf('--%02d-%02d', $month, $day) : sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * @return array<string, mixed>
     */
    private static function partialDate(string $date): array
    {
        if (1 === preg_match('/^--(\d{2})-(\d{2})$/', $date, $m)) {
            return ['@type' => 'PartialDate', 'month' => (int) $m[1], 'day' => (int) $m[2]];
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date) + [0, 0, 0]);

        return ['@type' => 'PartialDate', 'year' => $year, 'month' => $month, 'day' => $day];
    }

    /**
     * @param array<string, mixed> $from
     */
    private static function string(array $from, string $key): ?string
    {
        $value = $from[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
