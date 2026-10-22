<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * The vocabulary of a contact's labelled lists — dates, relations, websites,
 * chat handles, custom fields — and the one cleaning every writer applies to
 * them: the contact form, JMAP Contact/set, the importers and the three
 * provider mappers.
 *
 * **Labels are free text.** The ones listed here are plMail's own: the page
 * translates them and the mappers translate them to a provider's types. Any
 * other label — Google's custom "Godmother", Apple's X-ABLabel — is kept as
 * typed, shown as typed and written back as it came.
 *
 * **Dates** are `YYYY-MM-DD`, or `--MM-DD` when the year is not known — the
 * vCard 4 spelling, which every source can say: Google leaves the year out,
 * Apple writes 1604 and says so in X-APPLE-OMIT-YEAR, Outlook writes 1604 or
 * 1601.
 */
final class ContactFields
{
    public const array DATE_LABELS = ['anniversary', 'other'];

    public const array RELATION_LABELS = [
        'spouse', 'partner', 'child', 'parent', 'mother', 'father', 'sibling',
        'brother', 'sister', 'friend', 'relative', 'manager', 'assistant', 'other',
    ];

    public const array URL_LABELS = ['home', 'work', 'blog', 'profile', 'other'];

    /** Chat and calling services; `sip` is a SIP address, the rest are handles on that service. */
    public const array IM_SERVICES = [
        'sip', 'xmpp', 'skype', 'teams', 'matrix', 'aim', 'msn', 'yahoo', 'icq', 'qq', 'googleTalk', 'other',
    ];

    public const array IM_LABELS = ['home', 'work', 'other'];

    /** Years that mean "no year" in Apple's and Outlook's birthdays. */
    private const array PLACEHOLDER_YEARS = ['1604', '1601', '0000'];

    private const int MAX_LENGTH = 1024;

    private const int MAX_ROWS = 50;

    /**
     * A date in either spelling above, from what a source wrote: `1980-05-12`,
     * `19800512`, `--0512`, `--05-12`, `1604-05-12`, an ISO timestamp
     * (Outlook's birthday). Null for anything that is not a real calendar
     * day.
     */
    public static function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if (1 === preg_match('/^--(\d{2})-?(\d{2})$/', $value, $m)) {
            return checkdate((int) $m[1], (int) $m[2], 2000) ? sprintf('--%s-%s', $m[1], $m[2]) : null;
        }

        if (1 !== preg_match('/^(\d{4})-?(\d{2})-?(\d{2})(?:[T ].*)?$/', $value, $m)) {
            return null;
        }

        if (true === in_array($m[1], self::PLACEHOLDER_YEARS, true)) {
            return checkdate((int) $m[2], (int) $m[3], 2000) ? sprintf('--%s-%s', $m[2], $m[3]) : null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%s-%s-%s', $m[1], $m[2], $m[3]) : null;
    }

    /**
     * @return array{year: ?int, month: int, day: int}
     */
    public static function dateParts(string $date): array
    {
        if (str_starts_with($date, '--')) {
            return ['year' => null, 'month' => (int) substr($date, 2, 2), 'day' => (int) substr($date, 5, 2)];
        }

        return ['year' => (int) substr($date, 0, 4), 'month' => (int) substr($date, 5, 2), 'day' => (int) substr($date, 8, 2)];
    }

    /**
     * A date from its parts; null when they do not make one.
     */
    public static function dateOf(?int $year, ?int $month, ?int $day): ?string
    {
        if (null === $month || null === $day) {
            return null;
        }

        return self::date(null === $year || 0 === $year
            ? sprintf('--%02d-%02d', $month, $day)
            : sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * A date from the form's day, month and optional year fields.
     */
    public static function fromForm(mixed $fields): ?string
    {
        if (false === is_array($fields)) {
            return null;
        }

        $number = static fn (string $key): ?int => is_numeric($fields[$key] ?? null) ? (int) $fields[$key] : null;

        return self::dateOf($number('year'), $number('month'), $number('day'));
    }

    /**
     * A labelled list as submitted or read: every row an array, every value
     * a trimmed string, rows whose $required key is empty dropped, an empty
     * label made `other`, duplicates and anything past MAX_ROWS left out.
     * $normalise can rewrite or reject (null) a row's required value — a date
     * that is not one.
     *
     * @param list<string>                 $keys     the row's keys, $required among them
     * @param (callable(string): ?string)|null $normalise
     *
     * @return list<array<string, string>>
     */
    public static function rows(mixed $rows, array $keys, string $required, ?callable $normalise = null): array
    {
        if (false === is_array($rows)) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ($rows as $row) {
            if (false === is_array($row)) {
                continue;
            }

            $clean = [];

            foreach ($keys as $key) {
                $value       = $row[$key] ?? '';
                $clean[$key] = mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, self::MAX_LENGTH);
            }

            if (null !== $normalise && '' !== $clean[$required]) {
                $clean[$required] = (string) $normalise($clean[$required]);
            }

            if ('' === $clean[$required]) {
                continue;
            }

            if (true === array_key_exists('label', $clean) && '' === $clean['label']) {
                $clean['label'] = 'other';
            }

            $key = mb_strtolower(implode("\x1F", $clean));

            if (true === isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $clean;

            if (count($out) >= self::MAX_ROWS) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{label: string, date: string}> */
    public static function dates(mixed $rows): array
    {
        /** @var list<array{label: string, date: string}> */
        return self::rows($rows, ['label', 'date'], 'date', self::date(...));
    }

    /** @return list<array{label: string, name: string}> */
    public static function relations(mixed $rows): array
    {
        /** @var list<array{label: string, name: string}> */
        return self::rows($rows, ['label', 'name'], 'name');
    }

    /** @return list<array{label: string, url: string}> */
    public static function urls(mixed $rows): array
    {
        /** @var list<array{label: string, url: string}> */
        return self::rows($rows, ['label', 'url'], 'url');
    }

    /** @return list<array{service: string, handle: string, label: string}> */
    public static function ims(mixed $rows): array
    {
        $rows = self::rows($rows, ['service', 'handle', 'label'], 'handle');

        foreach ($rows as &$row) {
            if ('' === $row['service']) {
                $row['service'] = 'other';
            }
        }

        /** @var list<array{service: string, handle: string, label: string}> */
        return $rows;
    }

    /** @return list<array{label: string, value: string}> */
    public static function customFields(mixed $rows): array
    {
        /** @var list<array{label: string, value: string}> */
        return self::rows($rows, ['label', 'value'], 'value');
    }

    /**
     * The scalar fields of a contact besides displayName, in the order the
     * page shows them. One list, so the form, JMAP and the merger cannot
     * forget one.
     */
    public const array SCALARS = [
        'namePrefix', 'givenName', 'middleName', 'familyName', 'nameSuffix',
        'phoneticGivenName', 'phoneticMiddleName', 'phoneticFamilyName',
        'nickname', 'company', 'phoneticCompany', 'department', 'jobTitle',
        'officeLocation', 'notes',
    ];

    /** The labelled lists, by property name; clean() cleans each. */
    public const array LISTS = ['dates', 'relations', 'urls', 'ims', 'customFields'];

    /**
     * @return list<array<string, string>>
     */
    public static function clean(string $list, mixed $rows): array
    {
        return match ($list) {
            'dates'        => self::dates($rows),
            'relations'    => self::relations($rows),
            'urls'         => self::urls($rows),
            'ims'          => self::ims($rows),
            'customFields' => self::customFields($rows),
            default        => throw new \InvalidArgumentException(sprintf('Unknown contact list "%s".', $list)),
        };
    }
}
