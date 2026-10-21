<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * Parses CSV contact exports into ParsedContact DTOs using a small header
 * alias map. First row is the header row (case-insensitive).
 */
final class CsvImporter
{
    private const array ALIASES = [
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
            $rows[] = str_getcsv($line);
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
                case 'givenName':
                case 'familyName':
                case 'nickname':
                case 'company':
                case 'jobTitle':
                case 'notes':
                    $dto->{$target} = $value;
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
