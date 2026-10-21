<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;

/**
 * Exports the address book as CSV: one row per contact, primary email/phone
 * (falling back to the first) and tags joined with '; '.
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

        fputcsv($handle, [
            'displayName', 'givenName', 'familyName', 'nickname', 'company',
            'jobTitle', 'email', 'phone', 'notes', 'tags',
        ]);

        foreach ($contacts as $contact) {
            fputcsv($handle, [
                $contact->displayName,
                $contact->givenName ?? '',
                $contact->familyName ?? '',
                $contact->nickname ?? '',
                $contact->company ?? '',
                $contact->jobTitle ?? '',
                $this->pickEmail($contact),
                $this->pickPhone($contact),
                $contact->notes ?? '',
                implode('; ', $contact->tags),
            ]);
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
