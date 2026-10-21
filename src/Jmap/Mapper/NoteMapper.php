<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Entity\Note\Note;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Note as a JMAP object, and its state token.
 *
 * Same model as TemplateMapper: the objects are the user's, a few hundred at most, so the state is
 * a hash of the whole mapped list rather than a counter, there is no Note/changes, and a client
 * that holds a different state fetches the list again.
 */
final class NoteMapper
{
    /** Every property of a Note, in the order they are documented. */
    public const array PROPERTIES = [
        'id', 'title', 'body', 'items', 'color', 'pinned', 'archived', 'trashed', 'links', 'labels', 'reminderAt', 'reminderSent', 'media', 'createdAt', 'updatedAt',
    ];

    /**
     * @return array<string, mixed>
     */
    public function note(Note $note): array
    {
        return [
            'id'        => (string) $note->id,
            'title'     => $note->title,
            'body'      => $note->body,
            'items'     => array_values($note->items),
            'color'     => $note->color,
            'pinned'    => $note->pinned,
            'archived'  => $note->archived,
            'trashed'   => null !== $note->trashedAt,
            'links'     => array_values($note->links),
            'labels'    => array_values($note->labels),
            'reminderAt'   => null === $note->reminderAt ? null : $this->utc($note->reminderAt),
            'reminderSent' => null !== $note->reminderSentAt,
            'media'        => array_values($note->media),
            'createdAt' => $this->utc($note->createdAt),
            'updatedAt' => $this->utc($note->updatedAt),
        ];
    }

    /**
     * @param list<array<string, mixed>> $objects already mapped
     */
    public function state(array $objects): string
    {
        usort($objects, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return substr(hash('xxh128', json_encode($objects, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** A JMAP UTCDate: ISO 8601 in UTC with a "Z". */
    private function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
