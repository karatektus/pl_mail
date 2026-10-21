<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;

/**
 * Exports the address book as CSV: one row per contact, primary email/phone
 * (falling back to the first), the first website, and tags joined with '; '.
 *
 * A summary for a spreadsheet, not a backup: the further emails and phones,
 * the addresses and the labelled lists do not fit one row. The vCard export
 * carries everything. The header names are CsvImporter's, so the file reads
 * back in.
 */
final class CsvExporter
{
    public function __construct(
        private ContactRepository $contacts,
    ) {
    }

    public function exportAll(User $user): string
    {
        $contacts = $this->contacts->findBy(
            ['usr' => $user, 'trashedAt' => null],
            ['displayName' => 'ASC'],
        );

        $handle = fopen('php://memory', 'r+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open memory stream for CSV export');
        }

        $scalars = array_values(array_diff(ContactFields::SCALARS, ['notes']));

        // escape: '' is RFC 4180's quoting alone; CsvImporter reads it the same way.
        fputcsv($handle, ['displayName', ...$scalars, 'birthday', 'email', 'phone', 'website', 'notes', 'tags'], escape: '');

        foreach ($contacts as $contact) {
            fputcsv($handle, [
                $contact->displayName,
                ...array_map(static fn (string $field): string => (string) $contact->{$field}, $scalars),
                $contact->birthday ?? '',
                $this->pickEmail($contact),
                $this->pickPhone($contact),
                $contact->urls[0]['url'] ?? '',
                $contact->notes ?? '',
                implode('; ', $contact->tags),
            ], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function pickEmail(Contact $contact): string
    {
        $first = '';
        foreach ($contact->emails as $email) {
            if ('' === $first) {
                $first = (string) $email->email;
            }
            if ($email->isPrimary) {
                return (string) $email->email;
            }
        }

        return $first;
    }

    private function pickPhone(Contact $contact): string
    {
        $first = '';
        foreach ($contact->phones as $phone) {
            if ('' === $first) {
                $first = (string) $phone->phone;
            }
            if ($phone->isPrimary) {
                return (string) $phone->phone;
            }
        }

        return $first;
    }
}
