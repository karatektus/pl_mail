<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * What was read out of a pasted text, before anybody saves it: the quick-add
 * page shows it as rows to keep, fix or untick, and the editor's "Paste
 * details" compares it with the contact on screen.
 *
 * Not a ParsedContact. That one is what a save or a sync applies, every field
 * final; this is a proposal with a per-row type somebody may change, a reason
 * when the AI changed a row, and the lines nothing could place. Turning one
 * into the other is the form's job — the rows carry the form's own names.
 *
 * A field's `type` is one of FIELD_TYPES, the names the contact form posts
 * (`jobTitle`, not "title"), so the page can put a row straight into an input
 * of that name. Its `label` is one of the form's labels for that type.
 */
final class ContactDraft
{
    public const string SOURCE_RULES = 'rules';
    public const string SOURCE_VCARD = 'vcard';
    public const string SOURCE_AI    = 'ai';

    public const array FIELD_TYPES = [
        'email', 'phone', 'address', 'url', 'jobTitle', 'company', 'department', 'notes', 'birthday', 'nickname',
    ];

    /** The labels each labelled type may carry; the others take none. */
    public const array LABELS = [
        'email'   => ['home', 'work', 'other'],
        'phone'   => ['mobile', 'home', 'work', 'other'],
        'address' => ['home', 'work', 'other'],
        'url'     => ContactFields::URL_LABELS,
    ];

    public const array NAME_PARTS = ['namePrefix', 'givenName', 'middleName', 'familyName', 'nameSuffix'];

    public string $source = self::SOURCE_RULES;

    public string $name = '';

    /** @var array<string, string> NAME_PARTS → value; empty ones left out */
    public array $parts = [];

    /**
     * @var list<array{type: string, label: string, value: string, address?: array<string, string>, reason?: string}>
     *
     * `address` is set for type address: street, postcode, city, region,
     * country, the form's own keys. `reason` is set on a row the AI changed
     * or added — possibly empty, when it gave none — and never by the rules,
     * so its presence is what marks a row as the AI's.
     */
    public array $fields = [];

    /** @var list<string> lines nothing could place, for the person to place */
    public array $unsure = [];

    /** @var list<string> lines left out on purpose — a greeting above a signature */
    public array $skipped = [];

    /**
     * @param array<string, string>|null $address
     */
    public function add(string $type, string $label, string $value, ?array $address = null, ?string $reason = null): void
    {
        $value = trim($value);

        if ('' === $value || false === \in_array($type, self::FIELD_TYPES, true)) {
            return;
        }

        $allowed = self::LABELS[$type] ?? null;
        $label   = null === $allowed ? '' : (\in_array($label, $allowed, true) ? $label : 'other');

        // The same row twice — a signature that repeats its address in the
        // footer — is one row.
        foreach ($this->fields as $field) {
            if ($field['type'] === $type && mb_strtolower($field['value']) === mb_strtolower($value)) {
                return;
            }
        }

        $row = ['type' => $type, 'label' => $label, 'value' => mb_substr($value, 0, 1024)];

        if (null !== $address) {
            $row['address'] = $address;
        }

        if (null !== $reason) {
            $row['reason'] = mb_substr(trim($reason), 0, 200);
        }

        $this->fields[] = $row;
    }

    /**
     * @return array{source: string, name: string, parts: array<string, string>, fields: list<array<string, mixed>>, unsure: list<string>, skipped: list<string>}
     */
    public function toArray(): array
    {
        return [
            'source'  => $this->source,
            'name'    => $this->name,
            'parts'   => $this->parts,
            'fields'  => $this->fields,
            'unsure'  => $this->unsure,
            'skipped' => $this->skipped,
        ];
    }
}
