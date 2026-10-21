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
 * **Reading** keeps what the rows model — names, nickname, organisation, title,
 * note, categories as tags, emails, phones and postal addresses with their
 * home/work/mobile labels and which one is preferred — and an inline photo for
 * the photo store. Parsing is sabre/vobject's, in its forgiving mode: vCard
 * 2.1, 3.0 and 4.0, folded lines, quoted-printable, grouped properties
 * (Apple's `item1.EMAIL`) and escaped text are its problem, not ours, and a
 * card it cannot read is skipped rather than failing a sync or an import.
 *
 * **Writing** never rebuilds a card the server sent. It reads the stored card,
 * compares each property plMail models with the contact's rows, and touches
 * only those that differ: a phone number that is still there keeps its
 * parameters and group, an unchanged name keeps its prefixes and middle names,
 * and everything plMail has no field for — the photo, a birthday, a phone's X-
 * properties — goes back exactly as it came. Only a contact that has never been
 * a card (a local one being exported, or created in a synced book) gets a new
 * one, built from the rows.
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
        $contact->givenName   = $parsed->givenName;
        $contact->familyName  = $parsed->familyName;
        $contact->nickname    = $parsed->nickname;
        $contact->company     = $parsed->company;
        $contact->jobTitle    = $parsed->jobTitle;
        $contact->notes       = $parsed->notes;
        $contact->tags        = $parsed->tags;

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
            trim(trim((string) $parsed->givenName) . ' ' . trim((string) $parsed->familyName)),
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
     */
    public function write(?string $existing, Contact $contact): string
    {
        $card = null === $existing ? null : $this->parse($existing);

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
    public function export(Contact $contact): string
    {
        return $this->write($contact->vcard, $contact);
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

        $parsed->uid         = $this->text($card, 'UID');
        $parsed->displayName = $this->text($card, 'FN');
        $parsed->nickname    = $this->text($card, 'NICKNAME');
        $parsed->jobTitle    = $this->text($card, 'TITLE');
        $parsed->notes       = $this->text($card, 'NOTE');
        $parsed->company     = $this->organization($card);

        [$parsed->givenName, $parsed->familyName] = $this->name($card);

        $parsed->tags = $this->categories($card);

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

    private function organization(VCard $card): ?string
    {
        $property = $card->select('ORG')[0] ?? null;

        if (false === $property instanceof Property) {
            return null;
        }

        $name = trim((string) ($property->getParts()[0] ?? ''));

        return '' === $name ? null : $name;
    }

    /**
     * Given and family name from N. Middle names count as given names, the way
     * a person would type them into one field.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function name(VCard $card): array
    {
        $property = $card->select('N')[0] ?? null;

        if (false === $property instanceof Property) {
            return [null, null];
        }

        $parts  = $property->getParts();
        $family = trim((string) ($parts[0] ?? ''));
        $given  = trim(trim((string) ($parts[1] ?? '')) . ' ' . trim((string) ($parts[2] ?? '')));

        return ['' === $given ? null : $given, '' === $family ? null : $family];
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

    private function applyEmails(ParsedContact $parsed, Contact $contact): void
    {
        $wanted = [];

        foreach ($parsed->emails as $row) {
            $wanted[$row['email']] = $row;
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
     * N is rewritten only when the given or family name actually changed: a
     * carefully structured N — a prefix, a suffix — cannot be rebuilt from two
     * fields and must not be flattened by an edit to a phone number. When it
     * is rewritten, the prefix and suffix it had are kept.
     */
    private function writeName(VCard $card, ParsedContact $before, Contact $contact): void
    {
        $hasN = [] !== $card->select('N');

        if (true === $hasN && $before->givenName === $contact->givenName && $before->familyName === $contact->familyName) {
            return;
        }

        $old = true === $hasN ? $card->select('N')[0]->getParts() : [];

        $given  = $contact->givenName;
        $family = $contact->familyName;

        // N is required in 3.0 and is what phones sort by; a contact with no
        // structured name gets one split from its display name.
        if (null === $given && null === $family) {
            $words  = preg_split('/\s+/', trim($contact->displayName)) ?: [];
            $family = count($words) >= 2 ? array_pop($words) : null;
            $given  = implode(' ', $words);
        }

        $this->replace($card, 'N', [
            (string) $family,
            (string) $given,
            '',
            (string) ($old[3] ?? ''),
            (string) ($old[4] ?? ''),
        ]);
    }

    /**
     * Unchanged, ORG keeps the units after the name.
     */
    private function writeOrganization(VCard $card, ParsedContact $before, Contact $contact): void
    {
        if ($before->company === $contact->company) {
            return;
        }

        $card->remove('ORG');

        if (null !== $contact->company && '' !== trim($contact->company)) {
            $card->add('ORG', [trim($contact->company)]);
        }
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
