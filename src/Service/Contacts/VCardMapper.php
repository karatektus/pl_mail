<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Domain\Helper\AddressHelper;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Sabre\VObject\Splitter\VCard as VCardSplitter;
use Throwable;

/**
 * The one place a vCard becomes a Contact and a Contact becomes a vCard.
 *
 * Three callers, one mapping, so a card means the same thing whichever way it
 * arrived: a CardDAV pull (read() then apply()), a CardDAV push (write()), and
 * the .vcf import and export (parseAll(), export()).
 *
 * **Reading** keeps everything a contact has here — the structured name and
 * its phonetic spellings, nickname, organisation and department, title,
 * office, note, categories as tags, emails, phones and postal addresses with
 * their labels and which one is preferred, the birthday and other dates,
 * related people, websites, chat handles, custom fields — and an inline photo
 * for the photo store. Parsing is sabre/vobject's, in its forgiving mode: vCard
 * 2.1, 3.0 and 4.0, folded lines, quoted-printable, grouped properties
 * (Apple's `item1.EMAIL`) and escaped text are its problem, not ours, and a
 * card it cannot read is skipped rather than failing a sync or an import.
 *
 * **Writing** never rebuilds a card the server sent. It reads the stored card,
 * compares each property plMail models with the contact's rows, and touches
 * only those that differ: a phone number that is still there keeps its
 * parameters and group, an unchanged name keeps its prefixes and middle names,
 * and everything plMail has no field for — a phone's X- properties, a key, a
 * sound — goes back exactly as it came. Only a contact that has never been a
 * card (a local one being exported, or created in a synced book) gets a new
 * one, built from the rows.
 *
 * **Where the newer fields live on a card**, read in every spelling the common
 * clients write and written in the one the most of them read:
 *
 *   - prefix, middle name, suffix: N; department: ORG's second part;
 *   - phonetic names: X-PHONETIC-FIRST-NAME, -MIDDLE-NAME, -LAST-NAME and
 *     X-PHONETIC-ORG (Apple's and Android's);
 *   - birthday: BDAY, `--MM-DD` with no year (Apple's 1604 is read as that);
 *   - an anniversary: ANNIVERSARY (4.0) or X-ANNIVERSARY (3.0); any other
 *     date: Apple's X-ABDATE;
 *   - related people: RELATED as text (4.0) or Apple's X-ABRELATEDNAMES
 *     (3.0); Evolution's X-SPOUSE, X-MANAGER and X-ASSISTANT are read too;
 *   - websites: URL; chat handles: IMPP as a URI (`xmpp:`, `sip:`,
 *     `skype:`…), and the X-JABBER family older cards use is read too;
 *   - office: X-PLMAIL-OFFICE, and custom fields X-PLMAIL-FIELD with the key
 *     in X-LABEL — vCard has no property for either, so they are plMail's own
 *     and a phone does not show them.
 *
 * A label TYPE cannot say (a custom one, or "anniversary" on a URL) is carried
 * the way Apple carries it: the property in a group with an X-ABLabel.
 *
 * **The photo** is written only when it was changed here (Contact::
 * $photoDirty) or the card is new: an unchanged PHOTO stays byte for byte.
 */
final readonly class VCardMapper
{
    private const string PRODID = '-//plMail//Contacts//EN';

    private const array PHOTO_MIME_BY_TYPE = [
        'JPEG' => 'image/jpeg',
        'JPG'  => 'image/jpeg',
        'PNG'  => 'image/png',
        'WEBP' => 'image/webp',
    ];

    /** IMPP's URI scheme → service; see ContactFields::IM_SERVICES. */
    private const array IM_SERVICE_BY_SCHEME = [
        'sip'      => 'sip',
        'xmpp'     => 'xmpp',
        'jabber'   => 'xmpp',
        'skype'    => 'skype',
        'msteams'  => 'teams',
        'matrix'   => 'matrix',
        'aim'      => 'aim',
        'msnim'    => 'msn',
        'msn'      => 'msn',
        'ymsgr'    => 'yahoo',
        'icq'      => 'icq',
        'qq'       => 'qq',
        'gtalk'    => 'googleTalk',
    ];

    /** The scheme a handle is written with; Apple's x-apple for a service with none. */
    private const array IM_SCHEME_BY_SERVICE = [
        'sip'        => 'sip',
        'xmpp'       => 'xmpp',
        'skype'      => 'skype',
        'teams'      => 'msteams',
        'matrix'     => 'matrix',
        'aim'        => 'aim',
        'msn'        => 'msnim',
        'yahoo'      => 'ymsgr',
        'icq'        => 'icq',
        'qq'         => 'x-apple',
        'googleTalk' => 'xmpp',
        'other'      => 'x-apple',
    ];

    /** The one-property-per-service spelling of vCard 3.0 clients. */
    private const array IM_LEGACY = [
        'X-JABBER'         => 'xmpp',
        'X-SKYPE'          => 'skype',
        'X-SKYPE-USERNAME' => 'skype',
        'X-AIM'            => 'aim',
        'X-MSN'            => 'msn',
        'X-YAHOO'          => 'yahoo',
        'X-ICQ'            => 'icq',
        'X-QQ'             => 'qq',
        'X-GOOGLE-TALK'    => 'googleTalk',
        'X-SIP'            => 'sip',
    ];

    /** What each list is read from, so a rewrite of the list removes all of them. */
    private const array LIST_PROPERTIES = [
        'dates'        => ['ANNIVERSARY', 'X-ANNIVERSARY', 'X-ABDATE'],
        'relations'    => ['RELATED', 'X-ABRELATEDNAMES', 'X-SPOUSE', 'X-MANAGER', 'X-ASSISTANT'],
        'urls'         => ['URL'],
        'ims'          => ['IMPP', 'X-JABBER', 'X-SKYPE', 'X-SKYPE-USERNAME', 'X-AIM', 'X-MSN', 'X-YAHOO', 'X-ICQ', 'X-QQ', 'X-GOOGLE-TALK', 'X-SIP'],
        'customFields' => ['X-PLMAIL-FIELD'],
    ];

    /** RFC 6350's RELATED types that are also plMail relations. */
    private const array RELATED_TYPES = ['spouse', 'child', 'parent', 'sibling', 'friend'];

    // ── Reading ───────────────────────────────────────────────────────────────

    /**
     * Every card in a .vcf file, in file order. Cards that cannot be read are
     * left out.
     *
     * @return list<ParsedContact>
     */
    public function parseAll(string $content): array
    {
        $stream = fopen('php://memory', 'r+');

        if (false === $stream) {
            return [];
        }

        fwrite($stream, $content);
        rewind($stream);

        $parsed = [];

        try {
            $splitter = new VCardSplitter($stream, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);

            while (true) {
                try {
                    $card = $splitter->getNext();
                } catch (Throwable) {
                    // One malformed card in a phone's export: skip it and carry on.
                    continue;
                }

                if (null === $card) {
                    break;
                }

                if ($card instanceof VCard) {
                    $parsed[] = $this->fromComponent($card);
                }
            }
        } catch (Throwable) {
            // The splitter refused the file as a whole; whatever it read stands.
        } finally {
            fclose($stream);
        }

        return $parsed;
    }

    /**
     * One card, as a CardDAV server sends it. Null when it is not a vCard
     * sabre can read.
     */
    public function read(string $vcard): ?ParsedContact
    {
        $card = $this->parse($vcard);

        return null === $card ? null : $this->fromComponent($card);
    }

    /**
     * Make the contact's rows say what the card says.
     *
     * Children are reconciled by value rather than replaced, so an address
     * that is still there keeps its row (and its id, which JMAP clients hold).
     * The photo is not handled here: storing it is a file write, and the
     * caller decides whether to (see AddressBookSync).
     */
    public function apply(ParsedContact $parsed, Contact $contact): void
    {
        $contact->displayName = $this->displayNameOf($parsed);

        foreach (ContactFields::SCALARS as $field) {
            $contact->{$field} = null === $parsed->{$field} ? null : mb_substr($parsed->{$field}, 0, 'notes' === $field ? 65535 : 255);
        }

        $contact->tags     = $parsed->tags;
        $contact->birthday = ContactFields::date($parsed->birthday);

        foreach (ContactFields::LISTS as $list) {
            $contact->{$list} = ContactFields::clean($list, $parsed->{$list});
        }

        if (null !== $parsed->uid) {
            $contact->uid = mb_substr($parsed->uid, 0, 255);
        }

        $this->applyEmails($parsed, $contact);
        $this->applyPhones($parsed, $contact);
        $this->applyAddresses($parsed, $contact);
    }

    /**
     * The name a card is shown by: FN, else its structured name, else its
     * first address, else its organisation.
     */
    public function displayNameOf(ParsedContact $parsed): string
    {
        foreach ([
            $parsed->displayName,
            trim(implode(' ', array_filter([trim((string) $parsed->givenName), trim((string) $parsed->middleName), trim((string) $parsed->familyName)]))),
            $parsed->emails[0]['email'] ?? null,
            $parsed->company,
        ] as $candidate) {
            $candidate = trim((string) $candidate);

            if ('' !== $candidate) {
                return mb_substr($candidate, 0, 255);
            }
        }

        return 'Unnamed contact';
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * The card to send for this contact: $existing with the rows patched in,
     * or a new card when there is none.
     *
     * $photo is the contact's photo as the photo store has it (null for
     * none). It goes on the card only when the card is new or the photo was
     * changed here; see the class comment.
     */
    public function write(?string $existing, Contact $contact, ?ContactPhoto $photo = null): string
    {
        $card  = null === $existing ? null : $this->parse($existing);
        $isNew = null === $card;

        if (null === $card) {
            $card = new VCard([
                'VERSION' => '3.0',
                'PRODID'  => self::PRODID,
                'UID'     => $contact->uid,
            ]);
        }

        $before = $this->fromComponent($card);

        if ($before->displayName !== $contact->displayName) {
            $this->replace($card, 'FN', $contact->displayName);
        }

        $this->writeName($card, $before, $contact);
        $this->writeText($card, 'NICKNAME', $before->nickname, $contact->nickname);
        $this->writeOrganization($card, $before, $contact);
        $this->writeText($card, 'TITLE', $before->jobTitle, $contact->jobTitle);
        $this->writeText($card, 'NOTE', $before->notes, $contact->notes);
        $this->writeText($card, 'X-PHONETIC-FIRST-NAME', $before->phoneticGivenName, $contact->phoneticGivenName);
        $this->writeText($card, 'X-PHONETIC-MIDDLE-NAME', $before->phoneticMiddleName, $contact->phoneticMiddleName);
        $this->writeText($card, 'X-PHONETIC-LAST-NAME', $before->phoneticFamilyName, $contact->phoneticFamilyName);
        $this->writeText($card, 'X-PHONETIC-ORG', $before->phoneticCompany, $contact->phoneticCompany);
        $this->writeText($card, 'X-PLMAIL-OFFICE', $before->officeLocation, $contact->officeLocation);
        $this->writeBirthday($card, $before, $contact);
        $this->writeLists($card, $before, $contact);

        if (true === $isNew || true === $contact->photoDirty) {
            $this->writePhoto($card, $photo);
        }

        if ($before->tags !== $contact->tags) {
            $card->remove('CATEGORIES');

            if ([] !== $contact->tags) {
                $card->add('CATEGORIES', $contact->tags);
            }
        }

        $this->writeEmails($card, $contact);
        $this->writePhones($card, $contact);
        $this->writeAddresses($card, $contact);

        if ([] === $card->select('UID')) {
            $card->add('UID', $contact->uid);
        }

        return $card->serialize();
    }

    /**
     * The card an export hands out: the server's card, patched, for a synced
     * contact — so a .vcf from plMail carries the birthday the phone added —
     * and one built from the rows for a local contact.
     */
    public function export(Contact $contact, ?ContactPhoto $photo = null): string
    {
        return $this->write($contact->vcard, $contact, $photo);
    }

    // ── Reading, in detail ────────────────────────────────────────────────────

    private function parse(string $vcard): ?VCard
    {
        try {
            $component = Reader::read($vcard, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (Throwable) {
            // ParseException, and anything else a malformed card provokes —
            // sabre throws more than one type for hostile input.
            return null;
        }

        return $component instanceof VCard ? $component : null;
    }

    private function fromComponent(VCard $card): ParsedContact
    {
        $parsed = new ParsedContact();

        $parsed->uid                = $this->text($card, 'UID');
        $parsed->displayName        = $this->text($card, 'FN');
        $parsed->nickname           = $this->text($card, 'NICKNAME');
        $parsed->jobTitle           = $this->text($card, 'TITLE');
        $parsed->notes              = $this->text($card, 'NOTE');
        $parsed->phoneticGivenName  = $this->text($card, 'X-PHONETIC-FIRST-NAME');
        $parsed->phoneticMiddleName = $this->text($card, 'X-PHONETIC-MIDDLE-NAME');
        $parsed->phoneticFamilyName = $this->text($card, 'X-PHONETIC-LAST-NAME');
        $parsed->phoneticCompany    = $this->text($card, 'X-PHONETIC-ORG');
        $parsed->officeLocation     = $this->text($card, 'X-PLMAIL-OFFICE');

        [$parsed->company, $parsed->department] = $this->organization($card);

        [$parsed->familyName, $parsed->givenName, $parsed->middleName, $parsed->namePrefix, $parsed->nameSuffix] = $this->name($card);

        $parsed->tags         = $this->categories($card);
        $parsed->birthday     = ContactFields::date($this->text($card, 'BDAY'));
        $parsed->dates        = $this->datesOf($card);
        $parsed->relations    = $this->relationsOf($card);
        $parsed->urls         = $this->urlsOf($card);
        $parsed->ims          = $this->imsOf($card);
        $parsed->customFields = $this->customFieldsOf($card);

        foreach ($card->select('EMAIL') as $property) {
            $email = AddressHelper::email((string) $property->getValue());

            if (false === AddressHelper::isValidEmail($email) || true === $this->hasEmail($parsed, $email)) {
                continue;
            }

            $parsed->emails[] = ['email' => $email, 'label' => $this->label($property, false), 'primary' => $this->isPreferred($property)];
        }

        foreach ($card->select('TEL') as $property) {
            $phone = trim(preg_replace('/^tel:/i', '', (string) $property->getValue()) ?? '');

            if ('' === $phone || '' === $this->phoneKey($phone)) {
                continue;
            }

            $parsed->phones[] = ['phone' => $phone, 'label' => $this->label($property, true), 'primary' => $this->isPreferred($property)];
        }

        foreach ($card->select('ADR') as $property) {
            $address = $this->address($property);

            if (null !== $address) {
                $parsed->addresses[] = $address;
            }
        }

        $photo = $this->photo($card);

        if (null !== $photo) {
            [$parsed->photoBytes, $parsed->photoMime] = $photo;
            $parsed->photoVersion                     = sha1($photo[0]);
        } else {
            // A PHOTO this cannot read (a link, an unknown type) says there is
            // a photo; no PHOTO at all says there is none.
            $parsed->photoAbsent = [] === $card->select('PHOTO');
        }

        return $parsed;
    }

    private function text(VCard $card, string $name): ?string
    {
        $property = $card->select($name)[0] ?? null;

        if (false === $property instanceof Property) {
            return null;
        }

        $value = trim((string) $property->getValue());

        return '' === $value ? null : $value;
    }

    /**
     * The organisation's name and the first unit after it.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function organization(VCard $card): array
    {
        $property = $card->select('ORG')[0] ?? null;

        if (false === $property instanceof Property) {
            return [null, null];
        }

        $parts = $property->getParts();

        return [$this->nullIfEmpty((string) ($parts[0] ?? '')), $this->nullIfEmpty((string) ($parts[1] ?? ''))];
    }

    /**
     * N's five parts: family, given, middle, prefix, suffix.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}
     */
    private function name(VCard $card): array
    {
        $property = $card->select('N')[0] ?? null;
        $parts    = $property instanceof Property ? $property->getParts() : [];

        return [
            $this->nullIfEmpty((string) ($parts[0] ?? '')),
            $this->nullIfEmpty((string) ($parts[1] ?? '')),
            $this->nullIfEmpty((string) ($parts[2] ?? '')),
            $this->nullIfEmpty((string) ($parts[3] ?? '')),
            $this->nullIfEmpty((string) ($parts[4] ?? '')),
        ];
    }

    /**
     * @return list<array{label: string, date: string}>
     */
    private function datesOf(VCard $card): array
    {
        $rows = [];

        foreach (['ANNIVERSARY', 'X-ANNIVERSARY'] as $name) {
            foreach ($card->select($name) as $property) {
                $rows[] = ['label' => 'anniversary', 'date' => (string) $property->getValue()];
            }
        }

        foreach ($card->select('X-ABDATE') as $property) {
            $rows[] = ['label' => $this->groupLabel($card, $property) ?? 'other', 'date' => (string) $property->getValue()];
        }

        return ContactFields::dates($rows);
    }

    /**
     * @return list<array{label: string, name: string}>
     */
    private function relationsOf(VCard $card): array
    {
        $rows = [];

        foreach ($card->select('RELATED') as $property) {
            // A RELATED that is a URI points at another card; only a name is
            // something a person can read.
            if ('TEXT' !== strtoupper((string) $this->parameter($property, 'VALUE'))) {
                continue;
            }

            $rows[] = ['label' => $this->groupLabel($card, $property) ?? $this->relationType($property), 'name' => (string) $property->getValue()];
        }

        foreach ($card->select('X-ABRELATEDNAMES') as $property) {
            $rows[] = ['label' => $this->groupLabel($card, $property) ?? 'other', 'name' => (string) $property->getValue()];
        }

        foreach (['X-SPOUSE' => 'spouse', 'X-MANAGER' => 'manager', 'X-ASSISTANT' => 'assistant'] as $name => $label) {
            foreach ($card->select($name) as $property) {
                $rows[] = ['label' => $label, 'name' => (string) $property->getValue()];
            }
        }

        return ContactFields::relations($rows);
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function urlsOf(VCard $card): array
    {
        $rows = [];

        foreach ($card->select('URL') as $property) {
            $rows[] = ['label' => $this->groupLabel($card, $property) ?? $this->knownType($property, ContactFields::URL_LABELS), 'url' => (string) $property->getValue()];
        }

        return ContactFields::urls($rows);
    }

    /**
     * @return list<array{service: string, handle: string, label: string}>
     */
    private function imsOf(VCard $card): array
    {
        $rows = [];

        foreach ($card->select('IMPP') as $property) {
            $value = (string) $property->getValue();
            $label = $this->groupLabel($card, $property) ?? $this->knownType($property, ContactFields::IM_LABELS);

            [$service, $handle] = 1 === preg_match('/^([a-z][a-z0-9+.-]*):(.*)$/i', $value, $m)
                ? [self::IM_SERVICE_BY_SCHEME[strtolower($m[1])] ?? 'other', $m[2]]
                : ['other', $value];

            // Apple says which service in X-SERVICE-TYPE, whatever the scheme.
            $named = strtolower((string) $this->parameter($property, 'X-SERVICE-TYPE'));

            foreach (ContactFields::IM_SERVICES as $known) {
                if (strtolower($known) === $named) {
                    $service = $known;
                }
            }

            $rows[] = ['service' => $service, 'handle' => $handle, 'label' => $label];
        }

        foreach (self::IM_LEGACY as $name => $service) {
            foreach ($card->select($name) as $property) {
                $rows[] = ['service' => $service, 'handle' => (string) $property->getValue(), 'label' => $this->knownType($property, ContactFields::IM_LABELS)];
            }
        }

        // The same handle as IMPP and as X-JABBER, which some clients write
        // side by side, is one handle.
        $unique = [];

        foreach (ContactFields::ims($rows) as $row) {
            $unique[$row['service'] . "" . mb_strtolower($row['handle'])] ??= $row;
        }

        return array_values($unique);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function customFieldsOf(VCard $card): array
    {
        $rows = [];

        foreach ($card->select('X-PLMAIL-FIELD') as $property) {
            $rows[] = ['label' => (string) $this->parameter($property, 'X-LABEL'), 'value' => (string) $property->getValue()];
        }

        return ContactFields::customFields($rows);
    }

    /**
     * The X-ABLabel in the property's group, Apple's way of naming what TYPE
     * cannot: `_$!<Anniversary>!$_` for one of its own, plain text for one
     * the user typed. Null when the property has no group, or the group no
     * label.
     */
    private function groupLabel(VCard $card, Property $property): ?string
    {
        if (null === $property->group || '' === $property->group) {
            return null;
        }

        foreach ($card->select('X-ABLABEL') as $label) {
            if (0 !== strcasecmp((string) $label->group, $property->group)) {
                continue;
            }

            $text = trim((string) $label->getValue());

            if (1 === preg_match('/^_\$!<(.+)>!\$_$/', $text, $m)) {
                $text = $m[1];
            }

            return $this->ownLabel($text);
        }

        return null;
    }

    /** One of plMail's labels when the text is one, in any case; the text otherwise. */
    private function ownLabel(string $text): string
    {
        $text = trim($text);
        $key  = strtolower(str_replace([' ', '-', '_'], '', $text));

        foreach ([...ContactFields::DATE_LABELS, ...ContactFields::RELATION_LABELS, ...ContactFields::URL_LABELS, ...ContactFields::IM_LABELS] as $own) {
            if ($key === $own) {
                return $own;
            }
        }

        return match ($key) {
            'homepage'        => 'home',
            'domesticpartner' => 'partner',
            'son', 'daughter' => 'child',
            'kin'             => 'relative',
            default           => '' === $text ? 'other' : $text,
        };
    }

    /**
     * The first TYPE value that is one of $labels; `other` otherwise.
     *
     * @param list<string> $labels
     */
    private function knownType(Property $property, array $labels): string
    {
        foreach ($this->types($property) as $type) {
            if (true === in_array(strtolower($type), $labels, true)) {
                return strtolower($type);
            }
        }

        return 'other';
    }

    /** RELATED's TYPE, as one of plMail's relations where it maps onto one. */
    private function relationType(Property $property): string
    {
        foreach ($this->types($property) as $type) {
            $label = $this->ownLabel($type);

            if (true === in_array($label, ContactFields::RELATION_LABELS, true)) {
                return $label;
            }
        }

        return 'other';
    }

    /**
     * @return list<string>
     */
    private function categories(VCard $card): array
    {
        $tags = [];

        foreach ($card->select('CATEGORIES') as $property) {
            foreach ($property->getParts() as $part) {
                $tag = trim((string) $part);

                if ('' !== $tag && false === in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * @return array{label: string, street: ?string, city: ?string, region: ?string, postcode: ?string, country: ?string}|null
     */
    private function address(Property $property): ?array
    {
        $parts = $property->getParts();

        $address = [
            'label'    => $this->label($property, false),
            'street'   => $this->nullIfEmpty((string) ($parts[2] ?? '')),
            'city'     => $this->nullIfEmpty((string) ($parts[3] ?? '')),
            'region'   => $this->nullIfEmpty((string) ($parts[4] ?? '')),
            'postcode' => $this->nullIfEmpty((string) ($parts[5] ?? '')),
            'country'  => $this->nullIfEmpty((string) ($parts[6] ?? '')),
        ];

        foreach (['street', 'city', 'region', 'postcode', 'country'] as $key) {
            if (null !== $address[$key]) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Inline photos only. A PHOTO that is a link to somewhere else is left on
     * the card and not fetched: following a URL a server put in a card is a
     * request to an address nobody vetted.
     *
     * @return array{0: string, 1: string}|null [bytes, mime]
     */
    private function photo(VCard $card): ?array
    {
        $property = $card->select('PHOTO')[0] ?? null;

        if (false === $property instanceof Property) {
            return null;
        }

        $value = (string) $property->getValue();

        if (1 === preg_match('#^data:(image/(?:jpeg|png|webp))(;base64)?,(.*)$#is', $value, $match)) {
            $bytes = base64_decode(preg_replace('/\s+/', '', $match[3]) ?? '', true);

            return false === $bytes || '' === $bytes ? null : [$bytes, strtolower($match[1])];
        }

        // vCard 3.0's ENCODING=b: sabre has already decoded it, TYPE says what it is.
        $encoding = strtoupper((string) $this->parameter($property, 'ENCODING'));
        $type     = strtoupper((string) $this->parameter($property, 'TYPE'));
        $mime     = self::PHOTO_MIME_BY_TYPE[$type] ?? null;

        if (null === $mime || false === in_array($encoding, ['B', 'BASE64'], true) || '' === $value) {
            return null;
        }

        return [$value, $mime];
    }

    /**
     * home, work, mobile (phones only) or other, from the TYPE parameter.
     */
    private function label(Property $property, bool $isPhone): string
    {
        $types = $this->types($property);

        if (true === $isPhone && (in_array('CELL', $types, true) || in_array('MOBILE', $types, true))) {
            return 'mobile';
        }

        if (in_array('WORK', $types, true)) {
            return 'work';
        }

        if (in_array('HOME', $types, true)) {
            return 'home';
        }

        return 'other';
    }

    /**
     * vCard 3.0 says TYPE=pref, 4.0 says PREF=1.
     */
    private function isPreferred(Property $property): bool
    {
        return in_array('PREF', $this->types($property), true) || null !== $this->parameter($property, 'PREF');
    }

    /**
     * @return list<string> upper-cased
     */
    private function types(Property $property): array
    {
        $parameter = $property['TYPE'];

        if (false === $parameter instanceof Parameter) {
            return [];
        }

        $types = [];

        foreach ($parameter->getParts() as $part) {
            foreach (explode(',', (string) $part) as $type) {
                $type = strtoupper(trim($type));

                if ('' !== $type) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    private function hasEmail(ParsedContact $parsed, string $email): bool
    {
        foreach ($parsed->emails as $row) {
            if ($row['email'] === $email) {
                return true;
            }
        }

        return false;
    }

    // ── Applying to the rows ──────────────────────────────────────────────────

    /**
     * Matched on the address as ContactEmail stores it — lower-cased and
     * trimmed (AddressHelper::email()) — not as the source spelled it. Google
     * keeps "Dominik.Kreuzer@…" as typed; matched raw, it never equalled the
     * stored row, so the row was removed and the same address inserted again,
     * and Doctrine's inserts-before-deletes broke the unique key and the whole
     * sync with it. Two spellings of one address on a card are one row.
     */
    private function applyEmails(ParsedContact $parsed, Contact $contact): void
    {
        $wanted = [];

        foreach ($parsed->emails as $row) {
            $email = AddressHelper::email($row['email']);

            if (true === AddressHelper::isValidEmail($email)) {
                $wanted[$email] ??= $row;
            }
        }

        foreach ($contact->emails->toArray() as $existing) {
            $row = $wanted[$existing->email] ?? null;

            if (null === $row) {
                $contact->emails->removeElement($existing);

                continue;
            }

            $existing->label     = $row['label'];
            $existing->isPrimary = $row['primary'] ?? false;
            unset($wanted[$existing->email]);
        }

        foreach ($wanted as $email => $row) {
            ContactEmail::create($contact, $email, $row['label'], $row['primary'] ?? false);
        }
    }

    private function applyPhones(ParsedContact $parsed, Contact $contact): void
    {
        $wanted = [];

        foreach ($parsed->phones as $row) {
            $wanted[$this->phoneKey($row['phone'])] ??= $row;
        }

        foreach ($contact->phones->toArray() as $existing) {
            $key = $this->phoneKey($existing->phone);
            $row = $wanted[$key] ?? null;

            if (null === $row) {
                $contact->phones->removeElement($existing);

                continue;
            }

            $existing->label     = $row['label'];
            $existing->isPrimary = $row['primary'] ?? false;
            unset($wanted[$key]);
        }

        // Kept as written — "+44 20 7946 0000" reads better than its digits —
        // and matched by phoneKey(), so a reformatted number is the same row.
        foreach ($wanted as $row) {
            ContactPhone::create($contact, mb_substr(trim($row['phone']), 0, 64), $row['label'], $row['primary'] ?? false);
        }
    }

    private function applyAddresses(ParsedContact $parsed, Contact $contact): void
    {
        $wanted = [];

        foreach ($parsed->addresses as $row) {
            $wanted[$this->addressKey($row)] ??= $row;
        }

        foreach ($contact->addresses->toArray() as $existing) {
            $key = $this->addressKey($this->addressRow($existing));

            if (false === array_key_exists($key, $wanted)) {
                $contact->addresses->removeElement($existing);

                continue;
            }

            $existing->label = $wanted[$key]['label'];
            unset($wanted[$key]);
        }

        foreach ($wanted as $row) {
            $address = ContactAddress::create($contact, $row['label']);

            foreach (['street', 'city', 'region', 'postcode', 'country'] as $field) {
                $address->{$field} = $row[$field];
            }
        }
    }

    // ── Writing, in detail ────────────────────────────────────────────────────

    /**
     * N is rewritten only when one of its parts actually changed, so an edit
     * to a phone number leaves it as the server wrote it.
     */
    private function writeName(VCard $card, ParsedContact $before, Contact $contact): void
    {
        $hasN = [] !== $card->select('N');

        if (true === $hasN
            && $before->givenName === $contact->givenName
            && $before->familyName === $contact->familyName
            && $before->middleName === $contact->middleName
            && $before->namePrefix === $contact->namePrefix
            && $before->nameSuffix === $contact->nameSuffix) {
            return;
        }

        $given  = $contact->givenName;
        $family = $contact->familyName;

        // N is required in 3.0 and is what phones sort by; a contact with no
        // structured name gets one split from its display name.
        if (null === $given && null === $family && null === $contact->middleName) {
            $words  = preg_split('/\s+/', trim($contact->displayName)) ?: [];
            $family = count($words) >= 2 ? array_pop($words) : null;
            $given  = implode(' ', $words);
        }

        $this->replace($card, 'N', [
            (string) $family,
            (string) $given,
            (string) $contact->middleName,
            (string) $contact->namePrefix,
            (string) $contact->nameSuffix,
        ]);
    }

    /**
     * Unchanged, ORG keeps the units after the department.
     */
    private function writeOrganization(VCard $card, ParsedContact $before, Contact $contact): void
    {
        if ($before->company === $contact->company && $before->department === $contact->department) {
            return;
        }

        $old   = ($card->select('ORG')[0] ?? null)?->getParts() ?? [];
        $units = array_map(strval(...), array_slice($old, 2));

        $card->remove('ORG');

        $company    = trim((string) $contact->company);
        $department = trim((string) $contact->department);

        if ('' !== $company || '' !== $department) {
            $card->add('ORG', '' === $department && [] === $units ? [$company] : [$company, $department, ...$units]);
        }
    }

    /**
     * Apple's no-year 1604 stays as it was when the date did not change;
     * a new one is written in 4.0's basic format (`19801210`, `--1210` with
     * no year — RFC 6350 has no other), or as 3.0's `1980-12-10`, with
     * Apple's 1604 and X-APPLE-OMIT-YEAR for no year, 3.0 having no spelling
     * for that.
     */
    private function writeBirthday(VCard $card, ParsedContact $before, Contact $contact): void
    {
        if ($before->birthday === $contact->birthday) {
            return;
        }

        $card->remove('BDAY');

        if (null !== $contact->birthday) {
            $this->addDate($card, 'BDAY', $contact->birthday);
        }
    }

    private function addDate(VCard $card, string $name, string $date, ?string $group = null): void
    {
        $name = null === $group ? $name : $group . '.' . $name;

        if (true === $this->isVersion4($card)) {
            $card->add($name, str_starts_with($date, '--') ? '--' . str_replace('-', '', substr($date, 2)) : str_replace('-', '', $date));

            return;
        }

        if (false === str_starts_with($date, '--')) {
            $card->add($name, $date);

            return;
        }

        $card->add($name, '1604' . substr($date, 1), ['X-APPLE-OMIT-YEAR' => '1604']);
    }

    /**
     * Each list is compared whole with what the card says, and only a list
     * that changed is rewritten: every property it is read from goes (with
     * the X-ABLabel of its group), and the rows are written in the spelling
     * the class comment gives.
     */
    private function writeLists(VCard $card, ParsedContact $before, Contact $contact): void
    {
        foreach (self::LIST_PROPERTIES as $list => $names) {
            if ($before->{$list} == $contact->{$list}) {
                continue;
            }

            foreach ($names as $name) {
                foreach ($card->select($name) as $property) {
                    $this->removeWithLabel($card, $property);
                }
            }

            foreach ($contact->{$list} as $row) {
                match ($list) {
                    'dates'        => $this->addDateRow($card, $row),
                    'relations'    => $this->addRelation($card, $row),
                    'urls'         => $this->addLabelled($card, 'URL', $row['url'], $row['label'], ['home', 'work']),
                    'ims'          => $this->addIm($card, $row),
                    'customFields' => $card->add('X-PLMAIL-FIELD', $row['value'], ['X-LABEL' => $row['label']]),
                };
            }
        }
    }

    /** @param array{label: string, date: string} $row */
    private function addDateRow(VCard $card, array $row): void
    {
        if ('anniversary' === $row['label']) {
            $this->addDate($card, true === $this->isVersion4($card) ? 'ANNIVERSARY' : 'X-ANNIVERSARY', $row['date']);

            return;
        }

        $group = $this->newGroup($card);
        $this->addDate($card, 'X-ABDATE', $row['date'], $group);
        $card->add($group . '.X-ABLabel', $this->appleLabel($row['label']));
    }

    /** @param array{label: string, name: string} $row */
    private function addRelation(VCard $card, array $row): void
    {
        if (true === $this->isVersion4($card) && true === in_array($row['label'], self::RELATED_TYPES, true)) {
            $card->add('RELATED', $row['name'], ['VALUE' => 'text', 'TYPE' => $row['label']]);

            return;
        }

        $group = $this->newGroup($card);
        $card->add($group . '.X-ABRELATEDNAMES', $row['name']);
        $card->add($group . '.X-ABLabel', $this->appleLabel($row['label']));
    }

    /** @param array{service: string, handle: string, label: string} $row */
    private function addIm(VCard $card, array $row): void
    {
        $scheme = self::IM_SCHEME_BY_SERVICE[$row['service']] ?? 'x-apple';
        $handle = preg_replace('/^' . preg_quote($scheme, '/') . ':/i', '', $row['handle']) ?? $row['handle'];
        $params = 'x-apple' === $scheme || 'googleTalk' === $row['service'] ? ['X-SERVICE-TYPE' => ucfirst($row['service'])] : [];

        $this->addLabelled($card, 'IMPP', $scheme . ':' . $handle, $row['label'], ['home', 'work'], $params);
    }

    /**
     * A property whose label TYPE can say carries it there; any other label
     * goes in an X-ABLabel beside it, in a group of their own.
     *
     * @param list<string>          $types  the labels that are TYPE values for this property
     * @param array<string, string> $params
     */
    private function addLabelled(VCard $card, string $name, string $value, string $label, array $types, array $params = []): void
    {
        if ('other' === $label || true === in_array($label, $types, true)) {
            $card->add($name, $value, 'other' === $label ? $params : [...$params, 'TYPE' => strtoupper($label)]);

            return;
        }

        $group = $this->newGroup($card);
        $card->add($group . '.' . $name, $value, $params);
        $card->add($group . '.X-ABLabel', $this->appleLabel($label));
    }

    /** Apple's own spelling for the labels it knows, so its app translates them. */
    private function appleLabel(string $label): string
    {
        return match ($label) {
            'anniversary', 'other', 'spouse', 'partner', 'child', 'parent', 'mother', 'father',
            'sibling', 'brother', 'sister', 'friend', 'manager', 'assistant', 'home', 'work' => '_$!<' . ucfirst($label) . '>!$_',
            'profile' => 'profile',
            default   => $label,
        };
    }

    /** The first `itemN` group the card does not use yet. */
    private function newGroup(VCard $card): string
    {
        $used = [];

        foreach ($card->children() as $child) {
            if ($child instanceof Property && null !== $child->group) {
                $used[strtolower($child->group)] = true;
            }
        }

        for ($n = 1; ; ++$n) {
            if (false === isset($used['item' . $n])) {
                return 'item' . $n;
            }
        }
    }

    /** Remove a property, and the X-ABLabel of its group if nothing else in the group is left. */
    private function removeWithLabel(VCard $card, Property $property): void
    {
        $group = $property->group;
        $card->remove($property);

        if (null === $group || '' === $group) {
            return;
        }

        $rest = [];

        foreach ($card->children() as $child) {
            if ($child instanceof Property && 0 === strcasecmp((string) $child->group, $group)) {
                $rest[] = $child;
            }
        }

        if ([] !== array_filter($rest, static fn (Property $p): bool => 'X-ABLABEL' !== strtoupper($p->name))) {
            return;
        }

        foreach ($rest as $label) {
            $card->remove($label);
        }
    }

    /**
     * The card's PHOTO, replaced by the contact's — or removed, when the
     * contact has none. Inline in either version's spelling.
     */
    private function writePhoto(VCard $card, ?ContactPhoto $photo): void
    {
        $card->remove('PHOTO');

        if (null === $photo) {
            return;
        }

        if (true === $this->isVersion4($card)) {
            $card->add($card->createProperty('PHOTO', 'data:' . $photo->mime . ';base64,' . base64_encode($photo->bytes), [], 'URI'));

            return;
        }

        $card->add('PHOTO', $photo->bytes, ['ENCODING' => 'b', 'TYPE' => strtoupper($photo->extension())]);
    }

    private function writeText(VCard $card, string $name, ?string $before, ?string $after): void
    {
        if ($before === $after) {
            return;
        }

        $card->remove($name);

        if (null !== $after && '' !== trim($after)) {
            $card->add($name, $after);
        }
    }

    private function writeEmails(VCard $card, Contact $contact): void
    {
        $wanted = [];

        foreach ($contact->emails as $email) {
            $wanted[$email->email] = [$email->label, $email->isPrimary];
        }

        $this->reconcile(
            $card,
            'EMAIL',
            $wanted,
            static fn (Property $property): string => AddressHelper::email((string) $property->getValue()),
            false,
            static fn (string $key): string => $key,
        );
    }

    private function writePhones(VCard $card, Contact $contact): void
    {
        $wanted = [];
        $values = [];

        foreach ($contact->phones as $phone) {
            $key          = $this->phoneKey($phone->phone);
            $wanted[$key] = [$phone->label, $phone->isPrimary];
            $values[$key] = $phone->phone;
        }

        $this->reconcile(
            $card,
            'TEL',
            $wanted,
            fn (Property $property): string => $this->phoneKey((string) $property->getValue()),
            true,
            static fn (string $key): string => $values[$key],
        );
    }

    /**
     * Each property of $name whose value is still wanted stays — group,
     * parameters and the server's own formatting intact — and only has its
     * TYPE rewritten if the label was changed here. The rest go, and what is
     * new is added.
     *
     * @param array<string, array{0: string, 1: bool}> $wanted   key => [label, primary]
     * @param callable(Property): string               $keyOf
     * @param callable(string): string                 $valueOf  key => value to write for a new property
     */
    private function reconcile(VCard $card, string $name, array $wanted, callable $keyOf, bool $isPhone, callable $valueOf): void
    {
        foreach ($card->select($name) as $property) {
            $key = $keyOf($property);

            if (false === array_key_exists($key, $wanted)) {
                $card->remove($property);

                continue;
            }

            [$label, $primary] = $wanted[$key];
            unset($wanted[$key]);

            if ($this->label($property, $isPhone) !== $label || $this->isPreferred($property) !== $primary) {
                $this->retype($card, $property, $label, $primary, $isPhone);
            }
        }

        foreach ($wanted as $key => [$label, $primary]) {
            $card->add($name, $valueOf((string) $key), ['TYPE' => $this->typesFor($card, $label, $primary, $isPhone)]);
        }
    }

    /**
     * Swap the label-ish TYPE values for the new label, keeping the others
     * (VOICE, INTERNET, X-…), and the preference.
     */
    private function retype(VCard $card, Property $property, string $label, bool $primary, bool $isPhone): void
    {
        $kept = array_values(array_filter(
            $this->types($property),
            static fn (string $type): bool => false === in_array($type, ['HOME', 'WORK', 'CELL', 'MOBILE', 'PREF'], true),
        ));

        $types = array_values(array_unique([...$kept, ...$this->typesFor($card, $label, $primary, $isPhone)]));

        unset($property['TYPE'], $property['PREF']);

        if ([] !== $types) {
            $property['TYPE'] = $types;
        }

        if (true === $primary && true === $this->isVersion4($card)) {
            $property['PREF'] = '1';
        }
    }

    /**
     * @return list<string>
     */
    private function typesFor(VCard $card, string $label, bool $primary, bool $isPhone): array
    {
        $types = match ($label) {
            'home'   => ['HOME'],
            'work'   => ['WORK'],
            'mobile' => true === $isPhone ? ['CELL'] : [],
            default  => [],
        };

        if (true === $primary && false === $this->isVersion4($card)) {
            $types[] = 'PREF';
        }

        return $types;
    }

    private function writeAddresses(VCard $card, Contact $contact): void
    {
        $wanted = [];

        foreach ($contact->addresses as $address) {
            $row                             = $this->addressRow($address);
            $wanted[$this->addressKey($row)] = $row;
        }

        foreach ($card->select('ADR') as $property) {
            $row = $this->address($property);
            $key = null === $row ? null : $this->addressKey($row);

            if (null === $key || false === array_key_exists($key, $wanted)) {
                $card->remove($property);

                continue;
            }

            if ($row['label'] !== $wanted[$key]['label']) {
                $this->retype($card, $property, $wanted[$key]['label'], false, false);
            }

            unset($wanted[$key]);
        }

        foreach ($wanted as $row) {
            $card->add(
                'ADR',
                ['', '', (string) $row['street'], (string) $row['city'], (string) $row['region'], (string) $row['postcode'], (string) $row['country']],
                ['TYPE' => $this->typesFor($card, $row['label'], false, false)],
            );
        }
    }

    private function replace(VCard $card, string $name, string|array $value): void
    {
        $card->remove($name);
        $card->add($name, $value);
    }

    private function isVersion4(VCard $card): bool
    {
        $version = $card->select('VERSION')[0] ?? null;

        return $version instanceof Property && str_starts_with((string) $version->getValue(), '4');
    }

    private function parameter(Property $property, string $name): ?string
    {
        $parameter = $property[$name];

        return $parameter instanceof Parameter ? (string) $parameter->getValue() : null;
    }

    // ── Keys ──────────────────────────────────────────────────────────────────

    /**
     * Digits and a leading +: "+49 30 123-45" and "+493012345" are one number.
     */
    private function phoneKey(string $phone): string
    {
        $phone  = trim(preg_replace('/^tel:/i', '', $phone) ?? '');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return '' === $digits ? '' : (str_starts_with($phone, '+') ? '+' : '') . $digits;
    }

    /**
     * @return array{label: string, street: ?string, city: ?string, region: ?string, postcode: ?string, country: ?string}
     */
    private function addressRow(ContactAddress $address): array
    {
        return [
            'label'    => $address->label,
            'street'   => $address->street,
            'city'     => $address->city,
            'region'   => $address->region,
            'postcode' => $address->postcode,
            'country'  => $address->country,
        ];
    }

    /**
     * @param array{street: ?string, city: ?string, region: ?string, postcode: ?string, country: ?string} $row
     */
    private function addressKey(array $row): string
    {
        return mb_strtolower(implode("\x1F", array_map(
            static fn (?string $part): string => trim((string) $part),
            [$row['street'], $row['city'], $row['region'], $row['postcode'], $row['country']],
        )));
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
