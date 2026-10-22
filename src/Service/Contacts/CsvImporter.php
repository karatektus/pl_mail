<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * Parses CSV contact exports into ParsedContact DTOs using a small header
 * alias map. First row is the header row (case-insensitive).
 *
 * The map also knows CsvExporter's own headers (`displayName`, `givenName`,
 * … lower-cased), so plMail's export reads back in whole.
 */
final class CsvImporter
{
    private const array ALIASES = [
        'displayname' => 'displayName',
        'nameprefix' => 'namePrefix',
        'prefix' => 'namePrefix',
        'name prefix' => 'namePrefix',
        'givenname' => 'givenName',
        'middlename' => 'middleName',
        'middle name' => 'middleName',
        'additional name' => 'middleName',
        'familyname' => 'familyName',
        'namesuffix' => 'nameSuffix',
        'suffix' => 'nameSuffix',
        'name suffix' => 'nameSuffix',
        'phoneticgivenname' => 'phoneticGivenName',
        'phonetic first name' => 'phoneticGivenName',
        'phoneticmiddlename' => 'phoneticMiddleName',
        'phonetic middle name' => 'phoneticMiddleName',
        'phoneticfamilyname' => 'phoneticFamilyName',
        'phonetic last name' => 'phoneticFamilyName',
        'phoneticcompany' => 'phoneticCompany',
        'department' => 'department',
        'jobtitle' => 'jobTitle',
        'officelocation' => 'officeLocation',
        'office' => 'officeLocation',
        'office location' => 'officeLocation',
        'birthday' => 'birthday',
        'website' => 'website',
        'web page' => 'website',
        'url' => 'website',
        'name' => 'displayName',
        'full name' => 'displayName',
        'display name' => 'displayName',
        'first name' => 'givenName',
        'given name' => 'givenName',
        'last name' => 'familyName',
        'family name' => 'familyName',
        'surname' => 'familyName',
        'nickname' => 'nickname',
        'email' => 'email',
        'e-mail' => 'email',
        'email 1' => 'email',
        'email1' => 'email',
        'email 2' => 'email',
        'email2' => 'email',
        'phone' => 'phone',
        'mobile' => 'phone',
        'tel' => 'phone',
        'telephone' => 'phone',
        'phone 1' => 'phone',
        'phone1' => 'phone',
        'phone 2' => 'phone',
        'phone2' => 'phone',
        'company' => 'company',
        'organization' => 'company',
        'organisation' => 'company',
        'title' => 'jobTitle',
        'job title' => 'jobTitle',
        'notes' => 'notes',
        'note' => 'notes',
        'tags' => 'tags',
        'street' => 'street',
        'address' => 'street',
        'street address' => 'street',
        'city' => 'city',
        'region' => 'region',
        'state' => 'region',
        'province' => 'region',
        'postcode' => 'postcode',
        'postal code' => 'postcode',
        'zip' => 'postcode',
        'zip code' => 'postcode',
        'country' => 'country',
    ];

    /**
     * @return list<ParsedContact>
     */
    public function import(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $rows = [];
        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $rows[] = str_getcsv($line, escape: '');
        }
        if ([] === $rows) {
            return [];
        }

        $headers = array_map(
            fn ($h) => mb_strtolower(trim((string) $h)),
            $rows[0],
        );
        $map = [];
        foreach ($headers as $index => $header) {
            $target = self::ALIASES[$header] ?? null;
            if (null !== $target) {
                $map[$index] = $target;
            }
        }

        $out = [];
        foreach (array_slice($rows, 1) as $row) {
            $dto = $this->parseRow($row, $map);
            if (null !== $dto) {
                $out[] = $dto;
            }
        }

        return $out;
    }

    /**
     * @param list<string|null> $row
     * @param array<int, string> $map  column index -> field target
     */
    private function parseRow(array $row, array $map): ?ParsedContact
    {
        $dto = new ParsedContact();
        $address = [];

        foreach ($map as $index => $target) {
            $value = trim((string) ($row[$index] ?? ''));
            if ('' === $value) {
                continue;
            }
            switch ($target) {
                case 'displayName':
                case 'birthday':
                    $dto->{$target} = $value;
                    break;
                case 'website':
                    $dto->urls[] = ['label' => 'other', 'url' => $value];
                    break;
                case 'email':
                    $dto->emails[] = ['email' => $value, 'label' => 'other'];
                    break;
                case 'phone':
                    $dto->phones[] = ['phone' => $value, 'label' => 'other'];
                    break;
                case 'tags':
                    foreach (preg_split('/[;,]/', $value) ?: [] as $tag) {
                        $tag = trim($tag);
                        if ('' !== $tag && !in_array($tag, $dto->tags, true)) {
                            $dto->tags[] = $tag;
                        }
                    }
                    break;
                case 'street':
                case 'city':
                case 'region':
                case 'postcode':
                case 'country':
                    $address[$target] = $value;
                    break;
                default:
                    if (in_array($target, ContactFields::SCALARS, true)) {
                        $dto->{$target} = $value;
                    }
                    break;
            }
        }

        if ([] !== $address) {
            $dto->addresses[] = [
                'label' => 'other',
                'street' => $address['street'] ?? null,
                'city' => $address['city'] ?? null,
                'region' => $address['region'] ?? null,
                'postcode' => $address['postcode'] ?? null,
                'country' => $address['country'] ?? null,
            ];
        }

        if (null === $dto->displayName || '' === trim($dto->displayName)) {
            $fallback = trim(trim((string) $dto->givenName) . ' ' . trim((string) $dto->familyName));
            $dto->displayName = '' !== $fallback ? $fallback : null;
        }

        // Skip fully empty rows (no name, no email, no phone).
        if (null === $dto->displayName && [] === $dto->emails && [] === $dto->phones) {
            return null;
        }

        return $dto;
    }
}
