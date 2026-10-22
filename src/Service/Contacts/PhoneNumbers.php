<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * What a phone number in a pasted text is: written out in international form,
 * and whether it is a mobile.
 *
 * libphonenumber rather than a table of prefixes. "Is this a mobile" is a
 * question every country answers differently (German 015x–017x, UK 07, French
 * 06/07, and the US not at all), and a table here would be right for Germany
 * and quietly wrong everywhere else. The lite build: it has the numbering
 * plans and leaves out the carrier and geocoding data nobody here reads.
 *
 * A number with no country code needs a region to be read against. That is
 * the person's — their browser's language, see ContactAssistController — and
 * a number that still cannot be read is kept as typed, never dropped: the
 * person can see it and fix it, which they cannot do with a number that
 * vanished.
 */
final class PhoneNumbers
{
    private PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    /**
     * @return array{value: string, mobile: ?bool}|null null when $raw is not a phone number at all
     */
    public function read(string $raw, ?string $region): ?array
    {
        $raw = trim($raw);

        // Fewer than six digits is an extension, a fragment or a year — not
        // something to offer as a number.
        if (\strlen((string) preg_replace('/\D+/', '', $raw)) < 6) {
            return null;
        }

        try {
            $number = $this->util->parse($raw, null === $region ? null : strtoupper($region));
        } catch (NumberParseException) {
            return ['value' => $raw, 'mobile' => null];
        }

        if (false === $this->util->isPossibleNumber($number)) {
            return ['value' => $raw, 'mobile' => null];
        }

        $type = $this->util->getNumberType($number);

        return [
            'value'  => $this->util->format($number, PhoneNumberFormat::INTERNATIONAL),
            'mobile' => match ($type) {
                PhoneNumberType::MOBILE              => true,
                PhoneNumberType::FIXED_LINE_OR_MOBILE,
                PhoneNumberType::UNKNOWN             => null,
                default                              => false,
            },
        ];
    }

    /**
     * Whether two spellings are the same number: `+49 171 2345678` and
     * `0171 2345678` are. Used to tell a pasted number that is already on the
     * contact from a new one.
     */
    public function same(string $a, string $b, ?string $region): bool
    {
        $na = $this->read($a, $region)['value'] ?? $a;
        $nb = $this->read($b, $region)['value'] ?? $b;

        return preg_replace('/\D+/', '', $na) === preg_replace('/\D+/', '', $nb);
    }
}
