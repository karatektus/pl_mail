<?php

declare(strict_types=1);

namespace App\Service\AddressBook\CardDav;

use App\Domain\DTO\AddressBook\VCardFields;
use App\Domain\Helper\AddressHelper;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * vCards in and out, without losing what plMail does not understand.
 *
 * Reading is the easy half: name, addresses, numbers, organisation and note,
 * out of whatever version and dialect the server stored. Phones write 3.0,
 * Nextcloud writes 3.0 and 4.0 side by side, Apple writes 3.0 with grouped
 * properties (`item1.EMAIL`), and sabre/vobject reads all of them.
 *
 * Writing is the half that matters. **An edit is applied to the card the server
 * gave us, not to a card rebuilt from the fields.** A contact typically carries
 * far more than plMail shows — a photo, birthdays, postal addresses, IM
 * handles, X-properties a phone uses to link it to a messaging app — and the
 * only way to keep all of that is to change the properties we own and leave the
 * rest exactly as it came. The same goes for the parameters on the properties
 * we do own: an address that is still there keeps its TYPE=work and its
 * grouping, so a phone that showed it under "Work" still does.
 *
 * Reconciling lists (EMAIL, TEL) is by value: what is still in the edited list
 * keeps its property, what is gone is removed, what is new is appended with no
 * TYPE. Guessing a TYPE would be inventing data.
 */
final readonly class VCardConverter
{
    /** What a card created here identifies itself as. */
    private const string PRODID = '-//plMail//CardDAV//EN';

    /**
     * The fields plMail reads, or null when the text is not a vCard at all.
     *
     * $fallbackUid is used when the card has no UID, which RFC 6350 makes
     * optional and RFC 6352 does not: such cards exist, usually from an old
     * import, and the caller passes something stable — the href — so that the
     * same card gets the same identity on every pull.
     */
    public function read(string $vcard, string $fallbackUid): ?VCardFields
    {
        $card = $this->parse($vcard);

        if (null === $card) {
            return null;
        }

        $uid      = $this->text($card, 'UID') ?? $fallbackUid;
        $emails   = $this->emails($card);
        $phones   = $this->values($card, 'TEL');
        $org      = $this->organization($card);
        $fullName = $this->text($card, 'FN') ?? $this->nameFromN($card) ?? '';

        return new VCardFields(
            uid:          $uid,
            fullName:     $fullName,
            emails:       $emails,
            phones:       $phones,
            organization: $org,
            note:         $this->text($card, 'NOTE'),
        );
    }

    /**
     * The card with the edited fields applied — to $existing when there is
     * one, or to a fresh 3.0 card when the contact was created here.
     *
     * 3.0 for a new card because it is the version every CardDAV server and
     * every phone accepts; 4.0 is still refused by some appliances. An
     * existing card keeps whatever version it had.
     */
    public function write(?string $existing, VCardFields $fields): string
    {
        $card = null === $existing ? null : $this->parse($existing);

        if (null === $card) {
            $card = new VCard([
                'VERSION' => '3.0',
                'PRODID'  => self::PRODID,
                'UID'     => $fields->uid,
            ]);
        }

        $previousName = $this->text($card, 'FN');

        $this->replace($card, 'FN', $fields->fullName);

        // N is required in 3.0 and is what most phones sort by. Rewritten only
        // when the name actually changed (or there is none), because a
        // carefully structured N — prefixes, middle names — cannot be rebuilt
        // from FN and must not be flattened by an edit to the phone number.
        if ([] === $card->select('N') || $previousName !== $fields->fullName) {
            $this->replace($card, 'N', $this->structuredName($fields->fullName));
        }

        $this->reconcile($card, 'EMAIL', $fields->emails, true);
        $this->reconcile($card, 'TEL', $fields->phones, false);
        $this->replaceOrganization($card, $fields->organization);
        $this->replaceText($card, 'NOTE', $fields->note);

        if ([] === $card->select('UID')) {
            $card->add('UID', $fields->uid);
        }

        return $card->serialize();
    }

    /** A UID for a contact created here. */
    public function newUid(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    private function parse(string $vcard): ?VCard
    {
        try {
            $component = Reader::read($vcard, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (Throwable) {
            // ParseException, and anything else a malformed card provokes —
            // sabre throws more than one type for hostile input, and a card
            // that cannot be read is skipped, never a failed sync.
            return null;
        }

        return $component instanceof VCard ? $component : null;
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
     * Addresses, lower-cased and de-duplicated, invalid ones dropped — the same
     * normalisation the harvested contacts get, so the two lists merge on
     * equal strings.
     *
     * @return list<string>
     */
    private function emails(VCard $card): array
    {
        $emails = [];

        foreach ($this->values($card, 'EMAIL') as $value) {
            $email = AddressHelper::email($value);

            if (true === AddressHelper::isValidEmail($email)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /** @return list<string> */
    private function values(VCard $card, string $name): array
    {
        $values = [];

        foreach ($card->select($name) as $property) {
            $value = trim((string) $property->getValue());

            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /** The organisation name, without the units ORG may carry after it. */
    private function organization(VCard $card): ?string
    {
        $property = $card->select('ORG')[0] ?? null;

        if (false === $property instanceof Property) {
            return null;
        }

        $parts = $property->getParts();
        $name  = trim((string) ($parts[0] ?? ''));

        return '' === $name ? null : $name;
    }

    /**
     * "Given Family" out of N, for a card with no FN — legal in 2.1, and still
     * what some old exports look like.
     */
    private function nameFromN(VCard $card): ?string
    {
        $property = $card->select('N')[0] ?? null;

        if (false === $property instanceof Property) {
            return null;
        }

        $parts = $property->getParts();
        $name  = trim(sprintf('%s %s', $parts[1] ?? '', $parts[0] ?? ''));

        return '' === $name ? null : $name;
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * N from a display name: the last word as the family name, the rest as the
     * given names. Wrong for some naming traditions, and the reason it is only
     * applied when the name was changed here — see write().
     *
     * @return list<string> family; given; additional; prefix; suffix
     */
    private function structuredName(string $fullName): array
    {
        $words = preg_split('/\s+/', trim($fullName)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => '' !== $word));

        if (count($words) < 2) {
            return [$words[0] ?? '', '', '', '', ''];
        }

        $family = array_pop($words);

        return [$family, implode(' ', $words), '', '', ''];
    }

    /**
     * @param list<string> $wanted
     */
    private function reconcile(VCard $card, string $name, array $wanted, bool $caseInsensitive): void
    {
        $key = static fn (string $value): string => true === $caseInsensitive ? mb_strtolower(trim($value)) : trim($value);

        $remaining = [];

        foreach ($wanted as $value) {
            $remaining[$key($value)] = $value;
        }

        foreach ($card->select($name) as $property) {
            $value = $key((string) $property->getValue());

            if (true === array_key_exists($value, $remaining)) {
                // Still wanted: the property stays, parameters and group intact.
                unset($remaining[$value]);

                continue;
            }

            $card->remove($property);
        }

        foreach ($remaining as $value) {
            $card->add($name, $value);
        }
    }

    private function replaceOrganization(VCard $card, ?string $organization): void
    {
        if (null === $organization || '' === trim($organization)) {
            $card->remove('ORG');

            return;
        }

        // Unchanged: the units after the name are kept.
        if ($this->organization($card) === trim($organization)) {
            return;
        }

        $this->replace($card, 'ORG', trim($organization));
    }

    private function replaceText(VCard $card, string $name, ?string $value): void
    {
        if (null === $value || '' === trim($value)) {
            $card->remove($name);

            return;
        }

        $this->replace($card, $name, $value);
    }

    /**
     * Set a single-valued property, replacing every copy of it.
     *
     * @param string|list<string> $value
     */
    private function replace(VCard $card, string $name, string|array $value): void
    {
        $card->remove($name);
        $card->add($name, $value);
    }
}
