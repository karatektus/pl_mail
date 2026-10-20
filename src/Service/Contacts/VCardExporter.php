<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;

/**
 * Exports contacts as vCard 3.0.
 */
final class VCardExporter
{
    public function __construct(
        private ContactRepository $contacts,
    ) {
    }

    public function exportOne(Contact $contact): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'N:' . $this->esc((string) $contact->familyName) . ';' . $this->esc((string) $contact->givenName) . ';;;';
        $lines[] = 'FN:' . $this->esc($contact->displayName);
        if (null !== $contact->company && '' !== $contact->company) {
            $lines[] = 'ORG:' . $this->esc($contact->company);
        }
        if (null !== $contact->jobTitle && '' !== $contact->jobTitle) {
            $lines[] = 'TITLE:' . $this->esc($contact->jobTitle);
        }
        foreach ($contact->emails as $email) {
            $lines[] = 'EMAIL;TYPE=' . strtoupper($email->label) . ':' . $email->email;
        }
        foreach ($contact->phones as $phone) {
            $lines[] = 'TEL;TYPE=' . strtoupper($phone->label) . ':' . $phone->phone;
        }
        foreach ($contact->addresses as $address) {
            $lines[] = 'ADR;TYPE=' . strtoupper($address->label) . ':;;'
                . $this->esc((string) $address->street) . ';'
                . $this->esc((string) $address->city) . ';'
                . $this->esc((string) $address->region) . ';'
                . $this->esc((string) $address->postcode) . ';'
                . $this->esc((string) $address->country);
        }
        if (null !== $contact->notes && '' !== $contact->notes) {
            $lines[] = 'NOTE:' . $this->esc($contact->notes);
        }
        $lines[] = 'END:VCARD';

        $out = '';
        foreach ($lines as $line) {
            $out .= $this->fold($line) . "\r\n";
        }

        return $out;
    }

    public function exportAll(User $user): string
    {
        $contacts = $this->contacts->findBy(
            ['usr' => $user, 'trashedAt' => null],
            ['displayName' => 'ASC'],
        );

        $out = '';
        foreach ($contacts as $contact) {
            $out .= $this->exportOne($contact);
        }

        return $out;
    }

    /**
     * Escape vCard text: \ -> \\, newline -> \n, , -> \,, ; -> \;.
     */
    private function esc(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("\r\n", "\n", $value);
        $value = str_replace("\r", "\n", $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace(',', '\\,', $value);

        return str_replace(';', '\\;', $value);
    }

    /**
     * Fold lines at 75 octets. Approximation: uses mb_strcut so a multibyte
     * character is never split, though the byte count may undershoot 75 when
     * multibyte chars are present.
     */
    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = mb_strcut($line, 0, 75, 'UTF-8');
            if ('' === $cut) {
                break;
            }
            $out .= $cut . "\r\n ";
            $line = substr($line, strlen($cut));
        }

        return $out . $line;
    }
}
