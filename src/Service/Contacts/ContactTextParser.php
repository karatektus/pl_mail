<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * Reads a contact out of text somebody pasted: an email signature, a
 * `Name <address>` header line, an address block, or a whole vCard.
 *
 * RULES, NOT A MODEL
 * ──────────────────
 * This runs on every keystroke of the quick-add box and needs no AI host, so
 * it is patterns: an `@` is an email, `www.` a website, a line that starts
 * "Mobil" or "+49" a phone, "Street 12, 12345 City" an address. What the
 * rules cannot place they say so about — `unsure` lines the page asks the
 * person to place — rather than guessing; a guess that is wrong is worse than
 * a question, because it looks like an answer. ContactAssistant is the
 * optional second pass for what patterns cannot do (which of two lines is the
 * company, completing "Fax -99").
 *
 * A vCard is not guessed at at all: it goes through the importer the address
 * book's own import uses, field by field.
 *
 * Line by line, and a line split on ` | ` and ` · ` first: signatures put
 * "Head of Operations | Kranich GmbH" or "T +49 40 1234 | F +49 40 1235" on
 * one line, and the pieces are separate facts.
 */
final readonly class ContactTextParser
{
    private const int MAX_TEXT = 8000;

    /** Closing lines above a signature, in the languages plMail speaks. SignatureFinder reads it too. */
    public const string GREETING = '/^(mit freundlichen gr|freundliche gr|viele gr|beste gr|liebe gr|herzliche gr|sch(ö|oe)ne gr|lg\b|vg\b|mfg\b|best regards|kind regards|warm regards|regards|best,?$|cheers|thanks|thank you|many thanks|sincerely|cordialement|bien (à|a) vous|salutations)/iu';

    /** How a line names the kind of phone on it. */
    private const string PHONE_PREFIX = '/^(?<kind>mobil(?:e|funk)?|handy|cell(?:ular)?|portable|m|tel(?:efon|ephone)?\.?|t|phone|fon|f|fax|t[ée]l\.?|direkt|direct|zentrale|office|privat|home|work|b[üu]ro)\b\s*[.:]?\s*/iu';

    private const array PERSONAL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'web.de', 't-online.de',
        'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'icloud.com', 'me.com', 'mac.com',
        'yahoo.com', 'yahoo.de', 'posteo.de', 'posteo.net', 'mailbox.org', 'proton.me', 'protonmail.com',
        'freenet.de', 'arcor.de', 'aol.com', 'orange.fr', 'free.fr', 'laposte.net',
    ];

    private const array NAME_PREFIXES = ['dr.', 'dr', 'prof.', 'prof', 'mr.', 'mr', 'mrs.', 'mrs', 'ms.', 'ms', 'herr', 'frau', 'dipl.-ing.', 'ing.', 'mag.', 'm.', 'mme', 'mme.'];

    private const array NAME_SUFFIXES = ['jr.', 'jr', 'sr.', 'sr', 'phd', 'ph.d.', 'md', 'm.d.', 'ii', 'iii', 'iv', 'mba', 'msc', 'm.sc.', 'b.sc.'];

    /** Lower-case words that start a family name: van der Berg, von Weizsäcker, de la Cruz. */
    private const array NAME_PARTICLES = ['van', 'von', 'vom', 'zu', 'zum', 'de', 'der', 'den', 'del', 'della', 'di', 'da', 'du', 'la', 'le', 'ten', 'ter', 'af', 'al', 'bin', 'ibn'];

    private const string COMPANY_SUFFIX = '/\b(GmbH|gGmbH|AG|KG|OHG|GbR|UG|e\.\s?V\.|eG|SE|mbH|Inc\.?|Ltd\.?|LLC|LLP|PLC|Corp\.?|Co\.|S\.?A\.?|SARL|SAS|B\.?V\.?|N\.?V\.?|Oy|AB|ApS|AS)\b/u';

    /** Words that end or start a street name; a regex body, no delimiters. */
    private const string STREET_WORDS = '(stra(ß|ss)e|str\.|weg|platz|allee|gasse|ring|damm|ufer|chaussee|street|st\.|road|rd\.|avenue|ave\.|lane|drive|rue|boulevard|bd\.)';

    private const array COUNTRIES = [
        'deutschland', 'germany', 'österreich', 'oesterreich', 'austria', 'schweiz', 'switzerland', 'suisse',
        'france', 'frankreich', 'nederland', 'netherlands', 'niederlande', 'belgium', 'belgien', 'belgique',
        'luxembourg', 'luxemburg', 'italia', 'italy', 'italien', 'españa', 'spain', 'spanien', 'united kingdom',
        'uk', 'usa', 'united states', 'danmark', 'denmark', 'dänemark', 'sverige', 'sweden', 'schweden', 'polska', 'poland', 'polen',
    ];

    public function __construct(
        private VCardImporter $vcards,
        private PhoneNumbers $phones,
    ) {
    }

    /**
     * @param string|null $region the person's country (`DE`), for numbers written without one
     */
    public function parse(string $text, ?string $region = null): ContactDraft
    {
        $text = mb_substr(str_replace("\r\n", "\n", $text), 0, self::MAX_TEXT);

        if (1 === preg_match('/^\s*BEGIN:VCARD/i', $text)) {
            return $this->fromVCard($text);
        }

        $draft = new ContactDraft();
        $lines = $this->segments($text);
        $used  = [];
        $name  = '';
        $phoneRows = [];

        // "Name <address>" first, so its name wins over any loose line.
        foreach ($lines as $i => $line) {
            if (1 === preg_match('/^"?([^"<>@]+?)"?\s*<([^<>\s]+@[^<>\s]+)>$/u', $line, $m)) {
                $name = '' === $name ? trim($m[1]) : $name;
                $this->addEmail($draft, $m[2]);
                $used[$i] = true;
            }
        }

        foreach ($lines as $i => $line) {
            if (true === isset($used[$i])) {
                continue;
            }

            if (1 === preg_match(self::GREETING, $line)) {
                $draft->skipped[] = $line;
                $used[$i]          = true;
                continue;
            }

            if (1 === preg_match('/[^\s<>():,;]+@[^\s<>():,;]+\.[\p{L}]{2,}/u', $line, $m)) {
                $this->addEmail($draft, $m[0]);
                $used[$i] = true;
                continue;
            }

            if (1 === preg_match('~\b(https?://\S+|www\.[^\s,;]+)~iu', $line, $m)) {
                $draft->add('url', 'work', rtrim($m[1], '.'));
                $used[$i] = true;
                continue;
            }

            $phone = $this->phone($line, $region, $phoneRows);
            if (null !== $phone) {
                $phoneRows[] = $phone;
                $used[$i]    = true;
                continue;
            }

            // "Fax -99" or "F: -99": the end of the number on the line before,
            // with a different extension. Completed only when that number
            // ends in an extension to replace — otherwise it is left unsure.
            if (1 === preg_match('/^(?:fax|f|tel|t)\b\s*[.:]?\s*-\s?(\d{1,5})$/iu', $line, $m) && [] !== $phoneRows) {
                $last = $phoneRows[\count($phoneRows) - 1]['value'];
                if (1 === preg_match('/^(.*\d)\s?-\s?\d{1,5}$/u', $last, $base)) {
                    $phoneRows[] = ['label' => 'other', 'value' => $base[1] . '-' . $m[1]];
                    $used[$i]    = true;
                    continue;
                }
            }
        }

        $this->addresses($lines, $used, $draft);

        $rest = [];
        foreach ($lines as $i => $line) {
            if (false === isset($used[$i])) {
                $rest[] = $line;
            }
        }

        if ('' === $name) {
            $name = $this->takeName($rest);
        }

        $this->work($rest, $draft);

        foreach ($phoneRows as $row) {
            $draft->add('phone', $row['label'], $row['value']);
        }

        $draft->name  = $name;
        $draft->parts = self::splitName($name);

        return $draft;
    }

    /**
     * The name split into the contact's five parts. Static and public so the
     * AI's answer and the editor's save are split by the same rules.
     *
     * Prefixes and suffixes come off the ends; a lower-case particle starts
     * the family name ("Anna-Lena van der Berg" is Anna-Lena / van der Berg,
     * not Anna-Lena van der / Berg); the first word is the given name and
     * anything between is the middle name.
     *
     * Besides the known titles, any leading abbreviation of two or more
     * letters is a prefix ("Dr. Hon. Med.") and so are lower-case words
     * after a title ("Dr. rer. nat."); a one-letter initial ("J. R. R.
     * Tolkien") stays part of the name. Degrees at the end are a suffix
     * (see isDegree()).
     *
     * @return array<string, string>
     */
    public static function splitName(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];

        $prefix = [];
        while (\count($words) > 1 && self::isTitle($words[0], [] !== $prefix)) {
            $prefix[] = array_shift($words);
        }
        if ([] !== $prefix) {
            $parts['namePrefix'] = implode(' ', $prefix);
        }

        $suffix = [];
        while (\count($words) > 1 && self::isDegree(rtrim((string) end($words), ','), str_ends_with($words[\count($words) - 2], ','))) {
            array_unshift($suffix, rtrim((string) array_pop($words), ','));
        }
        // A trailing comma before the suffix ("Smith, Jr.") belongs to neither.
        if ([] !== $words) {
            $words[\count($words) - 1] = rtrim($words[\count($words) - 1], ',');
        }

        if (1 === \count($words)) {
            $parts['givenName'] = $words[0];
        } elseif (\count($words) > 1) {
            $family = \count($words) - 1;
            for ($i = 1; $i < \count($words) - 1; ++$i) {
                if (\in_array($words[$i], self::NAME_PARTICLES, true)) {
                    $family = $i;
                    break;
                }
            }
            $parts['givenName'] = $words[0];
            if ($family > 1) {
                $parts['middleName'] = implode(' ', \array_slice($words, 1, $family - 1));
            }
            $parts['familyName'] = implode(' ', \array_slice($words, $family));
        }

        if ([] !== $suffix) {
            $parts['nameSuffix'] = implode(' ', $suffix);
        }

        return $parts;
    }

    /**
     * Whether a leading word is part of the name's prefix: a known title, an
     * abbreviation of two or more letters, or — once a title has started the
     * prefix — a lower-case word that isn't a family-name particle.
     */
    private static function isTitle(string $word, bool $afterTitle): bool
    {
        if (\in_array(mb_strtolower($word), self::NAME_PREFIXES, true)) {
            return true;
        }
        if (str_ends_with($word, '.') && preg_match_all('/\p{L}/u', $word) >= 2) {
            return true;
        }

        return $afterTitle
            && 1 === preg_match('/^\p{Ll}[\p{Ll}.]*$/u', $word)
            && !\in_array($word, self::NAME_PARTICLES, true);
    }

    /**
     * Whether a trailing word is a degree: a known suffix, a dotted one
     * (M.Sc., LL.M.), a short mixed-case one (PhD, MSc, MPhil), or — only
     * after a comma — any all-caps group (", CPA"). An all-caps word without
     * the comma is the family name written in capitals ("Jean DUPONT").
     */
    private static function isDegree(string $word, bool $afterComma): bool
    {
        if (\in_array(mb_strtolower($word), self::NAME_SUFFIXES, true)) {
            return true;
        }
        if (1 === preg_match('/^(\p{L}{1,3}\.){2,}$/u', $word)) {
            return true;
        }
        if (mb_strlen($word) <= 5 && 1 === preg_match('/^\p{Lu}(\p{Lu}+\p{Ll}+|\p{Ll}+\p{Lu})$/u', $word)) {
            return true;
        }

        return $afterComma && 1 === preg_match('/^\p{Lu}{2,}$/u', $word);
    }

    /**
     * Whether an address's domain is a personal mailbox provider — the email
     * is a home address then, and any other domain is somebody's work.
     */
    public static function emailLabel(string $email): string
    {
        $domain = mb_strtolower((string) substr((string) strrchr($email, '@'), 1));

        return \in_array($domain, self::PERSONAL_DOMAINS, true) ? 'home' : 'work';
    }

    private function fromVCard(string $text): ContactDraft
    {
        $draft         = new ContactDraft();
        $draft->source = ContactDraft::SOURCE_VCARD;

        $cards = $this->vcards->import($text);
        $card  = $cards[0] ?? null;

        if (null === $card) {
            $draft->unsure[] = trim(strtok($text, "\n") ?: '');

            return $draft;
        }

        $draft->name = (string) ($card->displayName ?? trim(implode(' ', array_filter([$card->givenName, $card->familyName]))));

        foreach (ContactDraft::NAME_PARTS as $key) {
            if (null !== $card->{$key} && '' !== $card->{$key}) {
                $draft->parts[$key] = $card->{$key};
            }
        }
        if ([] === $draft->parts) {
            $draft->parts = self::splitName($draft->name);
        }

        foreach ($card->emails as $row) {
            $draft->add('email', $row['label'], $row['email']);
        }
        foreach ($card->phones as $row) {
            $draft->add('phone', $row['label'], $row['phone']);
        }
        foreach ($card->addresses as $row) {
            $address = array_filter([
                'street'   => (string) $row['street'],
                'postcode' => (string) $row['postcode'],
                'city'     => (string) $row['city'],
                'region'   => (string) $row['region'],
                'country'  => (string) $row['country'],
            ], static fn (string $v): bool => '' !== $v);
            $draft->add('address', $row['label'], self::addressLine($address), $address);
        }
        foreach ($card->urls as $row) {
            $draft->add('url', $row['label'], $row['url']);
        }
        foreach (['company', 'department', 'jobTitle', 'nickname', 'notes', 'birthday'] as $key) {
            if (null !== $card->{$key} && '' !== $card->{$key}) {
                $draft->add($key, '', $card->{$key});
            }
        }

        return $draft;
    }

    /**
     * The text's lines, each split into its ` | `-separated pieces, empty ones
     * and quoted reply lines (`> …`) left out.
     *
     * @return list<string>
     */
    private function segments(string $text): array
    {
        $out = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '>') || '--' === $line || '-- ' === $line) {
                continue;
            }
            foreach (preg_split('/\s+[|•·]\s+|\s{3,}|\t+/u', $line) ?: [] as $piece) {
                $piece = trim($piece, " \t,;");
                if ('' !== $piece) {
                    $out[] = $piece;
                }
            }
        }

        return $out;
    }

    private function addEmail(ContactDraft $draft, string $email): void
    {
        $email = trim($email, " \t<>.,;:()");
        $draft->add('email', self::emailLabel($email), $email);
    }

    /**
     * A phone row from a line, or null when the line is not one.
     *
     * A line is a phone when it names one ("Mobil:", "T", "Fax") or is
     * nothing but a number that starts like one (`+`, `0`, `(`). The label
     * comes from the name when there is one, else from libphonenumber's
     * mobile-or-not; a fax goes in as "other", the label plMail's contacts
     * have for it.
     *
     * @param list<array{label: string, value: string}> $seen
     *
     * @return array{label: string, value: string}|null
     */
    private function phone(string $line, ?string $region, array $seen): ?array
    {
        $kind = '';
        $rest = $line;

        if (1 === preg_match(self::PHONE_PREFIX, $line, $m)) {
            $kind = mb_strtolower($m['kind']);
            $rest = (string) mb_substr($line, mb_strlen($m[0]));
        }

        // Without a prefix only a line that is a number and nothing else.
        if ('' === $kind && 1 !== preg_match('/^[+(0][\d\s\/().\-]{5,}\d$/u', $rest)) {
            return null;
        }

        if (1 !== preg_match('/^[+(]?\d[\d\s\/().\-]{4,}\d/u', trim($rest), $n)) {
            return null;
        }

        $read = $this->phones->read($n[0], $region);
        if (null === $read) {
            return null;
        }

        $label = match (true) {
            1 === preg_match('/^(mobil|handy|cell|portable|m$)/u', $kind) => 'mobile',
            1 === preg_match('/^(fax|f$)/u', $kind)                          => 'other',
            1 === preg_match('/^(privat|home)/u', $kind)                     => 'home',
            true === $read['mobile']                                         => 'mobile',
            default                                                          => 'work',
        };

        // Kept as the line wrote it when that carries an extension the
        // international form would hide (`-12`), else the tidy form.
        $value = 1 === preg_match('/-\s?\d{1,5}$/u', $n[0]) ? trim($n[0]) : $read['value'];

        foreach ($seen as $row) {
            if ($this->phones->same($row['value'], $value, $region)) {
                return ['label' => $row['label'], 'value' => $row['value']];
            }
        }

        return ['label' => $label, 'value' => $value];
    }

    /**
     * Postal addresses: "Street 12, 12345 City" on one line, or "Street 12"
     * above "12345 City", with a country line under either taken along.
     *
     * Only the European shape — a postcode before the city. A US or UK address
     * is left as unsure lines for the person (or the AI) to place; a pattern
     * that half-matched one would split it in the wrong place.
     *
     * @param list<string>     $lines
     * @param array<int, bool> $used
     */
    private function addresses(array $lines, array &$used, ContactDraft $draft): void
    {
        $postcodeCity = '/^(?:([A-Z]{1,2})[-\s])?(\d{4,5})\s+(\p{L}[\p{L}\s.\-\/()]*)$/u';

        foreach ($lines as $i => $line) {
            if (true === isset($used[$i])) {
                continue;
            }

            $street = null;
            $city   = null;

            if (1 === preg_match('/^(.+?\d+\s?[a-zA-Z]?(?:[-\/]\d+)?),?\s+((?:[A-Z]{1,2}[-\s])?\d{4,5}\s+\p{L}.*)$/u', $line, $m)
                && 1 === preg_match($postcodeCity, $m[2], $c)) {
                $street = trim($m[1]);
                $city   = $c;
            } elseif (1 === preg_match($postcodeCity, $line, $c) && $i > 0 && false === isset($used[$i - 1])
                && 1 === preg_match('/\d+\s?[a-zA-Z]?$|' . self::STREET_WORDS . '/iu', $lines[$i - 1])) {
                $street        = $lines[$i - 1];
                $city          = $c;
                $used[$i - 1]  = true;
            }

            if (null === $city) {
                continue;
            }

            $used[$i] = true;
            $address  = ['street' => (string) $street, 'postcode' => $city[2], 'city' => trim($city[3])];

            $next = $lines[$i + 1] ?? null;
            if (null !== $next && false === isset($used[$i + 1]) && \in_array(mb_strtolower($next), self::COUNTRIES, true)) {
                $address['country'] = $next;
                $used[$i + 1]       = true;
            }

            $address = array_filter($address, static fn (string $v): bool => '' !== $v);
            $draft->add('address', 'work', self::addressLine($address), $address);
        }
    }

    /**
     * The first leftover line that looks like a person's name, taken out of
     * $rest. A line of two to five words, mostly letters, no company suffix.
     * Failing that, the first leftover line — the top line of a signature is
     * the name far more often than not.
     *
     * @param list<string> $rest
     */
    private function takeName(array &$rest): string
    {
        foreach ($rest as $i => $line) {
            $words = preg_split('/\s+/u', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if (\count($words) >= 2 && \count($words) <= 5
                && 1 === preg_match('/^[\p{L}\p{M}.\'’\-\s,]+$/u', $line)
                && 1 !== preg_match(self::COMPANY_SUFFIX, $line)) {
                array_splice($rest, $i, 1);

                return $line;
            }
        }

        return '' === ($rest[0] ?? '') ? '' : (string) array_shift($rest);
    }

    /**
     * Job title and company from what is left. A line with a company suffix
     * (GmbH, Inc.) is the company; the first other line is the job title. The
     * rest is unsure — two loose lines under a name could be a title and a
     * department, or a company and a slogan, and only a person (or the AI)
     * can tell which.
     *
     * @param list<string> $rest
     */
    private function work(array $rest, ContactDraft $draft): void
    {
        $title = false;

        foreach ($rest as $line) {
            if (1 === preg_match(self::COMPANY_SUFFIX, $line)) {
                $draft->add('company', '', $line);
                continue;
            }

            if (false === $title) {
                $draft->add('jobTitle', '', $line);
                $title = true;
                continue;
            }

            $draft->unsure[] = $line;
        }
    }

    /**
     * @param array<string, string> $address
     */
    private static function addressLine(array $address): string
    {
        $city = trim(($address['postcode'] ?? '') . ' ' . ($address['city'] ?? ''));

        return implode(', ', array_filter([$address['street'] ?? '', $city, $address['region'] ?? '', $address['country'] ?? ''], static fn (string $v): bool => '' !== $v));
    }
}
