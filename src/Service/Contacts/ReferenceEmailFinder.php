<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Finds messages referencing a contact: any non-deleted message of the user
 * where the contact's address appears in from/to/cc/bcc. Covers inbox, sent,
 * drafts, trash, labels, starred — everything not deleted.
 */
final class ReferenceEmailFinder
{
    public function __construct(
        private Connection $connection,
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

        // NOTE: four separate :emailsN placeholders — DBAL does not reliably
        // allow the same named parameter twice in one statement.
        $sql = 'SELECT m.id, m.subject, m.from_address, m.from_name, m.received_at,'
            . ' m.starred_at, m.has_attachments, mb.name AS mailbox_name'
            . ' FROM message m'
            . ' JOIN account a ON a.id = m.account_id'
            . ' LEFT JOIN mailbox mb ON mb.id = m.mailbox_id'
            . ' WHERE a.usr_id = :userId'
            . ' AND m.vanished_at IS NULL'
            . ' AND ('
            . ' LOWER(m.from_address) IN (:emails1)'
            . ' OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(m.to_addresses, \'[]\')::jsonb) e'
            . '   WHERE LOWER(e->>\'address\') IN (:emails2))'
            . ' OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(m.cc_addresses, \'[]\')::jsonb) e'
            . '   WHERE LOWER(e->>\'address\') IN (:emails3))'
            . ' OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(m.bcc_addresses, \'[]\')::jsonb) e'
            . '   WHERE LOWER(e->>\'address\') IN (:emails4))'
            . ' )'
            . ' ORDER BY m.received_at DESC NULLS LAST'
            . ' LIMIT :limit';

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'userId' => $user->id,
                'emails1' => $emails,
                'emails2' => $emails,
                'emails3' => $emails,
                'emails4' => $emails,
                'limit' => $limit,
            ],
            [
                'userId' => ParameterType::INTEGER,
                'emails1' => ArrayParameterType::STRING,
                'emails2' => ArrayParameterType::STRING,
                'emails3' => ArrayParameterType::STRING,
                'emails4' => ArrayParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );

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
