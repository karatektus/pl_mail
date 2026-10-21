<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * Parses vCard (3.0/4.0-ish) text into ParsedContact DTOs.
 * Handles line unfolding, N/FN/ORG/TITLE/EMAIL/TEL/ADR/NOTE and base64 PHOTO.
 */
final class VCardImporter
{
    private const array PHOTO_MIME_BY_TYPE = [
        'JPEG' => 'image/jpeg',
        'JPG' => 'image/jpeg',
        'PNG' => 'image/png',
        'WEBP' => 'image/webp',
    ];

    /**
     * @return list<ParsedContact>
     */
    public function import(string $content): array
    {
        $lines = $this->unfold($content);
        $cards = [];
        $current = null;

        foreach ($lines as $line) {
            $upper = strtoupper($line);
            if ('BEGIN:VCARD' === $upper) {
                $current = [];
                continue;
            }
            if ('END:VCARD' === $upper) {
                if (null !== $current) {
                    $cards[] = $current;
                    $current = null;
                }
                continue;
            }
            if (null !== $current) {
                $current[] = $line;
            }
        }

        $out = [];
        foreach ($cards as $cardLines) {
            $out[] = $this->parseCard($cardLines);
        }

        return $out;
    }

    /**
     * A line starting with a space or tab continues the previous line.
     *
     * @return list<string>
     */
    private function unfold(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $unfolded = [];
        foreach ($lines as $line) {
            if ('' !== $line && (' ' === $line[0] || "\t" === $line[0]) && [] !== $unfolded) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
            } else {
                $unfolded[] = $line;
            }
        }

        return $unfolded;
    }

    /**
     * @param list<string> $lines
     */
    private function parseCard(array $lines): ParsedContact
    {
        $dto = new ParsedContact();

        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if (false === $pos) {
                continue;
            }
            $head = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);

            $headParts = explode(';', $head);
            $prop = strtoupper(array_shift($headParts));
            // Group prefix: item1.EMAIL -> EMAIL
            if (str_contains($prop, '.')) {
                $prop = strtoupper(substr($prop, (int) strrpos($prop, '.') + 1));
            }
            $params = $this->parseParams($headParts);

            switch ($prop) {
                case 'FN':
                    $dto->displayName = $this->unescape($value);
                    break;
                case 'N':
                    $parts = explode(';', $value);
                    $family = $this->unescape($parts[0] ?? '');
                    $given = $this->unescape($parts[1] ?? '');
                    $middle = $this->unescape($parts[2] ?? '');
                    $dto->familyName = '' !== $family ? $family : null;
                    $fullGiven = trim($given . ' ' . $middle);
                    $dto->givenName = '' !== $fullGiven ? $fullGiven : null;
                    break;
                case 'NICKNAME':
                    $dto->nickname = $this->unescape($value) ?: null;
                    break;
                case 'ORG':
                    $org = explode(';', $value);
                    $dto->company = $this->unescape($org[0] ?? '') ?: null;
                    break;
                case 'TITLE':
                    $dto->jobTitle = $this->unescape($value) ?: null;
                    break;
                case 'EMAIL':
                    $email = trim($value);
                    if ('' !== $email) {
                        $dto->emails[] = ['email' => $email, 'label' => $this->emailLabel($params['TYPE'] ?? null)];
                    }
                    break;
                case 'TEL':
                    $phone = trim($value);
                    if ('' !== $phone) {
                        $dto->phones[] = ['phone' => $phone, 'label' => $this->phoneLabel($params['TYPE'] ?? null)];
                    }
                    break;
                case 'ADR':
                    $parts = explode(';', $value);
                    $address = [
                        'label' => $this->emailLabel($params['TYPE'] ?? null),
                        'street' => $this->nullIfEmpty($this->unescape($parts[2] ?? '')),
                        'city' => $this->nullIfEmpty($this->unescape($parts[3] ?? '')),
                        'region' => $this->nullIfEmpty($this->unescape($parts[4] ?? '')),
                        'postcode' => $this->nullIfEmpty($this->unescape($parts[5] ?? '')),
                        'country' => $this->nullIfEmpty($this->unescape($parts[6] ?? '')),
                    ];
                    $dto->addresses[] = $address;
                    break;
                case 'NOTE':
                    $dto->notes = $this->unescape($value) ?: null;
                    break;
                case 'PHOTO':
                    $photo = $this->parsePhoto($value, $params);
                    if (null !== $photo) {
                        [$dto->photoBytes, $dto->photoMime] = $photo;
                    }
                    break;
                default:
                    break;
            }
        }

        if (null === $dto->displayName || '' === trim($dto->displayName)) {
            $fallback = trim(trim((string) $dto->givenName) . ' ' . trim((string) $dto->familyName));
            $dto->displayName = '' !== $fallback ? $fallback : null;
        }

        return $dto;
    }

    /**
     * @param list<string> $headParts
     * @return array<string, string>
     */
    private function parseParams(array $headParts): array
    {
        $params = [];
        foreach ($headParts as $part) {
            $eq = strpos($part, '=');
            if (false === $eq) {
                // Bare param value without a key, e.g. EMAIL;HOME:...
                $params['TYPE'] = ($params['TYPE'] ?? '') . ',' . $part;
                continue;
            }
            $key = strtoupper(trim(substr($part, 0, $eq)));
            $val = trim(substr($part, $eq + 1), " \t\"'");
            if ('TYPE' === $key && isset($params['TYPE'])) {
                $params['TYPE'] .= ',' . $val;
            } else {
                $params[$key] = $val;
            }
        }

        return $params;
    }

    /**
     * First TYPE value, lowercased (TYPE=HOME,WORK or repeated TYPE params).
     */
    private function firstType(?string $type): string
    {
        if (null === $type || '' === trim($type)) {
            return '';
        }
        $parts = explode(',', $type);

        return strtolower(trim($parts[0]));
    }

    private function emailLabel(?string $type): string
    {
        return match ($this->firstType($type)) {
            'home' => 'home',
            'work' => 'work',
            default => 'other',
        };
    }

    private function phoneLabel(?string $type): string
    {
        return match ($this->firstType($type)) {
            'home' => 'home',
            'work' => 'work',
            'cell', 'mobile' => 'mobile',
            default => 'other',
        };
    }

    /**
     * @param array<string, string> $params
     * @return array{0: string, 1: string}|null  [bytes, mime]
     */
    private function parsePhoto(string $value, array $params): ?array
    {
        $value = trim($value);
        if (str_starts_with(strtolower($value), 'data:')) {
            // data:image/png;base64,....
            if (1 === preg_match('#^data:([^;,]+)(;base64)?,(.*)$#is', $value, $m)) {
                $mime = strtolower(trim($m[1]));
                $bytes = base64_decode(preg_replace('/\s+/', '', $m[3]) ?? '', true);
                if (false !== $bytes && '' !== $bytes && $this->isPhotoMime($mime)) {
                    return [$bytes, $mime];
                }
            }

            return null;
        }

        $type = strtoupper(trim($params['TYPE'] ?? ''));
        $mime = self::PHOTO_MIME_BY_TYPE[$type] ?? null;
        if (null === $mime) {
            return null;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $value) ?? '', true);
        if (false === $bytes || '' === $bytes) {
            return null;
        }

        return [$bytes, $mime];
    }

    private function isPhotoMime(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    /**
     * Unescape vCard text values: \\ -> backslash, \n -> newline,
     * \, -> comma, \; -> semicolon.
     */
    private function unescape(string $value): string
    {
        $value = str_replace('\\\\', "\x00", $value);
        $value = str_replace(['\\n', '\\N'], "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\;', ';', $value);

        return str_replace("\x00", '\\', $value);
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
