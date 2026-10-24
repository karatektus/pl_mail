<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Ai\AiFeature;
use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Service\Contacts\ContactDraft;
use App\Service\Contacts\ContactTextParser;

/**
 * The contact editor's "Improve with AI": a second pass over pasted text the
 * rules could not fully read, and suggestions for a saved contact from the
 * signature of their latest mail.
 *
 * THE RULES GO FIRST, AND THE MODEL IS SHOWN THEIR ANSWER
 * ───────────────────────────────────────────────────────
 * improve() sends the pasted text together with what ContactTextParser made
 * of it, and asks for that corrected. A model asked to read a signature from
 * scratch gets most of it right and rewrites the rest in its own spelling;
 * one asked to correct a reading changes what was wrong and leaves the rest,
 * and what it changed is then exactly what the page marks.
 *
 * JSON IN, VALIDATED ROW BY ROW
 * ─────────────────────────────
 * The answer is a JSON object the page never sees raw. Every row goes back
 * through ContactDraft::add(), which refuses a type the form has no input
 * for and turns a label it does not know into "other", so a model that
 * invents a "linkedin" field or a "cellular" label produces a row the form
 * can hold or no row at all — never one it cannot.
 *
 * NOTHING IS SAVED HERE. Both answers are proposals the person ticks, fixes
 * or ignores; the save is the form's, as for anything they typed.
 */
final readonly class ContactAssistant
{
    /** What of a paste goes to the model; the box itself allows more. */
    private const int TEXT_BUDGET = 4000;

    /** A low temperature: this is reading, not writing. */
    private const float TEMPERATURE = 0.1;

    private const string FIELDS_SPEC = 'Each field is {"type", "label", "value"} and, for an address, also'
        . ' "address": {"street", "postcode", "city", "region", "country"} (leave out empty keys).'
        . ' type is one of: email, phone, address, url, jobTitle, company, department, notes, birthday, nickname.'
        . ' label: for email home|work|other; for phone mobile|home|work|other (a fax is other);'
        . ' for address home|work|other; for url home|work|blog|profile|other; "" for every other type.'
        . ' Write phone numbers in international format (+49 …) when the country is clear, and keep an'
        . ' extension such as -12. A birthday is YYYY-MM-DD, or --MM-DD without a year.'
        . ' Copy names, companies and addresses exactly as written, but undo SHOUTING CAPITALS.'
        . ' Never invent anything that is not in the text.';

    public function __construct(
        private AiAssistant $ai,
        private AiPermissions $permissions,
    ) {
    }

    public function isAvailableFor(?User $user): bool
    {
        return $this->permissions->allows($user, AiFeature::ContactHelp);
    }

    /**
     * The rules' reading of $text, corrected by the model. Null when the
     * feature is off for this person, or the model gave no usable answer.
     *
     * A row the model added or changed carries a `reason` (empty if it gave
     * none); a row it kept as the rules had it does not, whatever the model
     * said about it — see the class note.
     *
     * @param string $language the language the person reads, for the reasons
     */
    public function improve(User $user, string $text, ContactDraft $rules, string $language): ?ContactDraft
    {
        if (false === $this->isAvailableFor($user)) {
            return null;
        }

        $text = trim(mb_substr($text, 0, self::TEXT_BUDGET));
        if ('' === $text) {
            return null;
        }

        $system = 'You read contact details out of text a person pasted: an email signature, a header line,'
            . ' an address. A pattern-based reader has already had a go; you are given its result and'
            . ' correct it. Fix wrong types and labels, place the lines it could not place (or leave them'
            . ' out if they are no contact detail, like a slogan), complete fragments such as "Fax -99"'
            . ' from the number above them, and split the name correctly into its parts.'
            . "\n\nAnswer with ONE JSON object and nothing else:"
            . ' {"name": "...", "parts": {"namePrefix", "givenName", "middleName", "familyName", "nameSuffix"},'
            . ' "fields": [ ... ]}. List every field the contact should have, not only the changes.'
            . ' ' . self::FIELDS_SPEC
            . ' For every field you added or changed compared with the reader\'s result, add "reason": a'
            . ' short phrase (at most ten words) in ' . self::languageName($language) . ' saying why.'
            . ' Fields you kept unchanged get no reason.';

        $userMessage = 'The pasted text:' . "\n" . $text
            . "\n\nThe reader's result:\n" . json_encode([
                'name'            => $rules->name,
                'parts'           => (object) $rules->parts,
                'fields'          => array_map(static fn (array $f): array => array_diff_key($f, ['reason' => true]), $rules->fields),
                'unplaced_lines'  => $rules->unsure,
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);

        $answer = $this->answer([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userMessage],
        ]);

        if (null === $answer) {
            return null;
        }

        $draft          = new ContactDraft();
        $draft->source  = ContactDraft::SOURCE_AI;
        $draft->skipped = $rules->skipped;
        $draft->name    = self::string($answer['name'] ?? null) ?? $rules->name;
        $draft->parts   = self::parts($answer['parts'] ?? null) ?? ContactTextParser::splitName($draft->name);

        $kept = [];
        foreach ($rules->fields as $field) {
            $kept[self::key($field['type'], $field['label'], $field['value'])] = true;
        }

        foreach (self::rows($answer['fields'] ?? null) as $row) {
            $unchanged = isset($kept[self::key($row['type'], $row['label'], $row['value'])]);
            $draft->add($row['type'], $row['label'], $row['value'], $row['address'], $unchanged ? null : ($row['reason'] ?? ''));
        }

        return $draft;
    }

    /**
     * Suggestions for a saved contact: what the signature of their latest mail
     * says that the contact does not (a new number, a changed job title, a
     * move), and plain fixes to what is there (a mobile filed as a work
     * number, a number not in international form).
     *
     * Each suggestion is a field row with a `reason`, in the shape
     * ContactDraft rows have; the page compares them with the form, as it
     * does pasted details, and nothing here decides what replaces what.
     *
     * @param array{text: string, receivedAt: ?\DateTimeImmutable, subject: ?string}|null $signature
     *
     * @return array{name: ?array{value: string, reason: string}, fields: list<array<string, mixed>>}|null
     */
    public function suggest(User $user, Contact $contact, ?array $signature, string $language): ?array
    {
        if (false === $this->isAvailableFor($user)) {
            return null;
        }

        $system = 'You help keep one contact in an address book up to date. You are given the contact as it'
            . ' is saved and, when there is one, the signature of the newest mail they sent.'
            . ' Suggest only: details in the signature the contact does not have; saved details the'
            . ' signature shows have changed (a new job title, a new number, a move); and plain fixes to'
            . ' saved details (a mobile number filed as a work number, a number not in international'
            . ' format, a name in capitals). Never suggest removing anything, and suggest nothing that is'
            . ' already saved as it should be. No suggestions at all is a fine answer.'
            . "\n\nAnswer with ONE JSON object and nothing else:"
            . ' {"name": null or {"value": "...", "reason": "..."}, "fields": [ ... ]}.'
            . ' ' . self::FIELDS_SPEC
            . ' A fix to a saved field repeats its value (fixed) with the right type and label.'
            . ' Every field and the name get "reason": a short phrase (at most ten words) in '
            . self::languageName($language) . ' saying where it comes from or why'
            . ($signature === null ? '.' : ' — for a detail from the signature, say so, with the date of the mail.');

        $saved = [
            'name'       => $contact->displayName,
            'company'    => $contact->company,
            'department' => $contact->department,
            'jobTitle'   => $contact->jobTitle,
            'emails'     => array_map(static fn ($e): array => ['label' => $e->label, 'value' => $e->email], $contact->emails->toArray()),
            'phones'     => array_map(static fn ($p): array => ['label' => $p->label, 'value' => $p->phone], $contact->phones->toArray()),
            'addresses'  => array_map(static fn ($a): array => array_filter([
                'label'    => $a->label,
                'street'   => $a->street,
                'postcode' => $a->postcode,
                'city'     => $a->city,
                'region'   => $a->region,
                'country'  => $a->country,
            ], static fn (?string $v): bool => null !== $v && '' !== $v), $contact->addresses->toArray()),
            'urls'       => $contact->urls,
            'birthday'   => $contact->birthday,
        ];

        $userMessage = "The saved contact:\n" . json_encode(array_filter($saved, static fn (mixed $v): bool => null !== $v && [] !== $v && '' !== $v), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);

        if (null !== $signature) {
            $userMessage .= "\n\nThe signature of their newest mail"
                . (null === $signature['receivedAt'] ? '' : ', received ' . $signature['receivedAt']->format('Y-m-d'))
                . ":\n" . $signature['text'];
        }

        $answer = $this->answer([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userMessage],
        ]);

        if (null === $answer) {
            return null;
        }

        // Through ContactDraft for its validation, then back out as rows.
        $draft = new ContactDraft();
        foreach (self::rows($answer['fields'] ?? null) as $row) {
            $draft->add($row['type'], $row['label'], $row['value'], $row['address'], $row['reason'] ?? '');
        }

        $name = null;
        if (\is_array($answer['name'] ?? null)) {
            $value = self::string($answer['name']['value'] ?? null);
            if (null !== $value && $value !== $contact->displayName) {
                $name = ['value' => $value, 'reason' => (string) self::string($answer['name']['reason'] ?? null)];
            }
        }

        return ['name' => $name, 'fields' => $draft->fields];
    }

    /**
     * The model's answer as an array, or null. Tolerates what chat models
     * wrap JSON in — a code fence, a sentence before it — by reading from
     * the first `{` to the last `}`.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>|null
     */
    private function answer(array $messages): ?array
    {
        $raw = $this->ai->chat(AiFeature::ContactHelp, $messages, self::TEMPERATURE);

        if (null === $raw) {
            return null;
        }

        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if (false === $start || false === $end || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array{type: string, label: string, value: string, address: ?array<string, string>, reason: ?string}>
     */
    private static function rows(mixed $fields): array
    {
        if (false === \is_array($fields)) {
            return [];
        }

        $out = [];

        foreach (\array_slice($fields, 0, 60) as $field) {
            if (false === \is_array($field)) {
                continue;
            }

            $type  = self::string($field['type'] ?? null);
            $value = self::string($field['value'] ?? null);

            if (null === $type) {
                continue;
            }

            $address = null;
            if ('address' === $type && \is_array($field['address'] ?? null)) {
                $address = [];
                foreach (['street', 'postcode', 'city', 'region', 'country'] as $key) {
                    $part = self::string($field['address'][$key] ?? null);
                    if (null !== $part) {
                        $address[$key] = $part;
                    }
                }
                $value ??= implode(', ', array_filter([$address['street'] ?? null, trim(($address['postcode'] ?? '') . ' ' . ($address['city'] ?? '')), $address['country'] ?? null]));
            }

            if (null === $value || '' === $value) {
                continue;
            }

            $out[] = [
                'type'    => $type,
                'label'   => (string) self::string($field['label'] ?? null),
                'value'   => $value,
                'address' => [] === $address ? null : $address,
                'reason'  => self::string($field['reason'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>|null
     */
    private static function parts(mixed $parts): ?array
    {
        if (false === \is_array($parts)) {
            return null;
        }

        $out = [];
        foreach (ContactDraft::NAME_PARTS as $key) {
            $value = self::string($parts[$key] ?? null);
            if (null !== $value) {
                $out[$key] = $value;
            }
        }

        return [] === $out ? null : $out;
    }

    private static function string(mixed $value): ?string
    {
        if (false === \is_string($value) && false === \is_int($value)) {
            return null;
        }

        $value = trim(mb_substr((string) $value, 0, 1024));

        return '' === $value ? null : $value;
    }

    private static function key(string $type, string $label, string $value): string
    {
        return $type . "\x1F" . $label . "\x1F" . mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? $value);
    }

    private static function languageName(string $locale): string
    {
        return match (substr($locale, 0, 2)) {
            'de'    => 'German',
            'fr'    => 'French',
            'zh'    => 'Simplified Chinese',
            default => 'English',
        };
    }
}
