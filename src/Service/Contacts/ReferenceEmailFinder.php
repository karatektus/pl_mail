<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Mail\Message\MessageContactRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Finds messages referencing a contact: any non-deleted message of the user
 * where the contact's address appears in from/to/cc/bcc. Covers inbox, sent,
 * drafts, trash, labels, starred — everything not deleted.
 */
final class ReferenceEmailFinder
{
    public function __construct(
        private readonly MessageContactRepository $messageContactRepository,
    ) {
    }

    /**
     * @return list<array{id: int, subject: ?string, fromAddress: ?string, fromName: ?string, receivedAt: ?string, mailbox: ?string, isStarred: bool, hasAttachment: bool}>
     */
    public function findForContact(User $user, Contact $contact, int $limit = 50): array
    {
        $emails = [];
        foreach ($contact->emails as $email) {
            $addr = mb_strtolower(trim((string) $email->email));
            if ('' !== $addr && !in_array($addr, $emails, true)) {
                $emails[] = $addr;
            }
        }
        if ([] === $emails) {
            return [];
        }

        $rows = $this->messageContactRepository->findExchangedWith($user, $emails, $limit);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'subject' => $row['subject'],
                'fromAddress' => $row['from_address'],
                'fromName' => $row['from_name'],
                'receivedAt' => $this->formatUtc($row['received_at']),
                'mailbox' => $row['mailbox_name'],
                'isStarred' => null !== $row['starred_at'],
                'hasAttachment' => (bool) $row['has_attachments'],
            ];
        }

        return $out;
    }

    private function formatUtc(mixed $value): ?string
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        return (new DateTimeImmutable((string) $value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
