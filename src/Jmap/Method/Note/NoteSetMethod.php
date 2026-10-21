<?php

declare(strict_types=1);

namespace App\Jmap\Method\Note;

use App\Entity\Note\Note;
use App\Jmap\Mapper\NoteMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Method\Template\TemplateMethodSupport;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Note\NoteRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Note/set" — plMail extension, `urn:plmail:params:jmap:notes`.
 *
 * Create, update and destroy, with the same shape and the same error vocabulary as Template/set.
 * Updates replace whole properties (`items` and `links` are sent complete); there is no
 * JSON-pointer patching, which keeps a phone and the web from merging each other's half-lists.
 * Concurrency is `ifInState`, as for templates: a client that wrote from an old list is told so
 * and re-reads.
 *
 * Everything is plain text and is stored as it arrives apart from control characters, which are
 * stripped, and sizes, which are refused rather than truncated — a note that silently loses its
 * tail is worse than one that is told it is too long.
 *
 * `reminderAt` is a UTCDate or null; a new time is a new reminder, sent once when it comes due
 * (app:notes:reminders), by email to the user and as a notification on their devices.
 *
 * `media` (pictures, drawings, recordings) is read-only here: files are added and removed through
 * /jmap/notes/{id}/media (App\Controller\Notes\NoteMediaApiController).
 *
 * `trashed` is a boolean on the wire and an instant in the table: sending true stamps the moment,
 * so a later purge can honour a retention period without a second column.
 */
final class NoteSetMethod implements JmapMethod
{
    use TemplateMethodSupport;

    private const array SETTABLE = ['title', 'body', 'items', 'color', 'pinned', 'archived', 'trashed', 'links', 'labels', 'reminderAt'];

    private const int MAX_BODY  = 50_000;
    private const int MAX_ITEMS = 300;
    private const int MAX_ITEM  = 1_000;
    private const int MAX_LINKS = 50;
    private const int MAX_LABELS = 20;

    public function __construct(
        private readonly NoteRepository         $notes,
        private readonly NoteMapper             $mapper,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\Note\NoteMediaStorage $media,
    ) {
    }

    public function name(): string
    {
        return 'Note/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'Note');

        $oldState  = $this->state($context);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The notes have changed since ifInState was issued.');
        }

        $created = $notCreated = $updated = $notUpdated = $notDestroyed = [];
        $destroyed = [];

        foreach ($this->objectMap($arguments['create'] ?? null, 'create') as $creationId => $properties) {
            $creationId = (string) $creationId;

            try {
                $note      = new Note();
                $note->usr = $context->user;
                $this->apply($note, $this->objectOf($properties));
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();

                continue;
            }

            $this->em->persist($note);
            // The id is the response, and what "#creationId" resolves to.
            $this->em->flush();

            $context->recordCreatedId($creationId, (string) $note->id);
            $mapped = $this->mapper->note($note);
            $created[$creationId] = [
                'id'        => $mapped['id'],
                'createdAt' => $mapped['createdAt'],
                'updatedAt' => $mapped['updatedAt'],
            ];
        }

        foreach ($this->objectMap($arguments['update'] ?? null, 'update') as $id => $patch) {
            $id   = (string) $id;
            $note = $this->owned($context->resolveId($id) ?? $id, $context);

            if (null === $note) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such Note.'];

                continue;
            }

            try {
                $this->apply($note, $this->objectOf($patch));
            } catch (MethodException $exception) {
                // Nothing of a refused patch may reach the flush below.
                $this->em->refresh($note);
                $notUpdated[$id] = $exception->toError();

                continue;
            }

            $updated[$id] = null;
        }

        $destroy = $arguments['destroy'] ?? [];

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id   = (string) $id;
            $note = $this->owned($real = $context->resolveId($id) ?? $id, $context);

            if (null === $note) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such Note.'];

                continue;
            }

            $this->media->removeAll($note);
            $this->em->remove($note);
            $destroyed[] = $real;
        }

        $this->em->flush();

        return [
            'oldState'     => $oldState,
            'newState'     => $this->state($context),
            'created'      => $this->map($created),
            'notCreated'   => $this->map($notCreated),
            'updated'      => $this->map($updated),
            'notUpdated'   => $this->map($notUpdated),
            'destroyed'    => $destroyed,
            'notDestroyed' => $this->map($notDestroyed),
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Put a create's properties or an update's patch onto the note.
     *
     * @param array<string, mixed> $properties
     */
    private function apply(Note $note, array $properties): void
    {
        $this->rejectUnsupported($properties, self::SETTABLE);

        if (true === array_key_exists('title', $properties)) {
            $note->title = $this->line($properties['title']);
        }

        if (true === array_key_exists('body', $properties)) {
            $note->body = $this->text($properties['body'], 'body', self::MAX_BODY);
        }

        if (true === array_key_exists('items', $properties)) {
            $note->items = $this->items($properties['items']);
        }

        if (true === array_key_exists('color', $properties)) {
            $color = $properties['color'];

            if (null !== $color && (false === is_string($color) || false === in_array($color, Note::COLORS, true))) {
                throw new MethodException('invalidProperties', sprintf('"color" must be null or one of: %s.', implode(', ', Note::COLORS)));
            }

            $note->color = $color;
        }

        foreach (['pinned', 'archived'] as $flag) {
            if (true === array_key_exists($flag, $properties)) {
                $note->{$flag} = $this->bool($properties[$flag], $flag);
            }
        }

        if (true === array_key_exists('trashed', $properties)) {
            $trashed = $this->bool($properties['trashed'], 'trashed');

            if (true === $trashed) {
                $note->trashedAt ??= new DateTimeImmutable();
            } else {
                $note->trashedAt = null;
            }
        }

        if (true === array_key_exists('links', $properties)) {
            $note->links = $this->links($properties['links']);
        }

        if (true === array_key_exists('labels', $properties)) {
            $note->labels = $this->labels($properties['labels']);
        }

        if (true === array_key_exists('reminderAt', $properties)) {
            $note->remindAt($this->instant($properties['reminderAt']));
        }
    }

    /** A UTCDate (or any ISO 8601 instant with an offset), or null. */
    private function instant(mixed $value): ?DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if (true === is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            try {
                return new DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
            }
        }

        throw new MethodException('invalidProperties', '"reminderAt" must be null or a UTCDate such as 2026-10-19T09:00:00Z.');
    }

    private function bool(mixed $value, string $property): bool
    {
        if (false === is_bool($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be true or false.', $property));
        }

        return $value;
    }

    /** Plain text with line breaks kept, control characters dropped, and a size limit. */
    private function text(mixed $value, string $property, int $max): string
    {
        if (null === $value) {
            return '';
        }

        if (false === is_string($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be a string.', $property));
        }

        $clean = str_replace(["\r\n", "\r"], "\n", $value);
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);

        if (mb_strlen($clean) > $max) {
            throw new MethodException('invalidProperties', sprintf('"%s" is longer than %d characters.', $property, $max));
        }

        return $clean;
    }

    /**
     * @return list<array{text: string, done: bool}>
     */
    private function items(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value) || count($value) > self::MAX_ITEMS) {
            throw new MethodException('invalidProperties', sprintf('"items" must be a list of at most %d entries.', self::MAX_ITEMS));
        }

        $items = [];

        foreach ($value as $item) {
            if (false === is_array($item) || false === array_key_exists('text', $item)) {
                throw new MethodException('invalidProperties', 'Each item needs a "text" and may carry a "done".');
            }

            $items[] = [
                'text' => $this->line($this->text($item['text'], 'item text', self::MAX_ITEM)),
                'done' => true === ($item['done'] ?? false),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{type: string, id: string, title: string|null}>
     */
    private function links(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value) || count($value) > self::MAX_LINKS) {
            throw new MethodException('invalidProperties', sprintf('"links" must be a list of at most %d entries.', self::MAX_LINKS));
        }

        $links = [];

        foreach ($value as $link) {
            $type = is_array($link) ? ($link['type'] ?? null) : null;
            $id   = is_array($link) ? ($link['id'] ?? null) : null;

            if (false === is_string($type) || false === in_array($type, Note::LINK_TYPES, true)) {
                throw new MethodException('invalidProperties', sprintf('A link\'s "type" must be one of: %s.', implode(', ', Note::LINK_TYPES)));
            }

            if (false === is_string($id) || '' === $id || mb_strlen($id) > 191) {
                throw new MethodException('invalidProperties', 'A link needs an "id" of at most 191 characters.');
            }

            $title = $link['title'] ?? null;
            $title = null === $title ? null : $this->line($title);

            // One link per target; a repeat only refreshes the title.
            $links[$type.'|'.$id] = ['type' => $type, 'id' => $id, 'title' => '' === $title ? null : $title];
        }

        return array_values($links);
    }

    /**
     * @return list<string>
     */
    private function labels(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value) || count($value) > self::MAX_LABELS) {
            throw new MethodException('invalidProperties', sprintf('"labels" must be a list of at most %d names.', self::MAX_LABELS));
        }

        return Note::cleanLabels($value);
    }

    private function owned(string $id, JmapContext $context): ?Note
    {
        if (1 !== preg_match('/^\d{1,9}$/', $id)) {
            return null;
        }

        $note = $this->notes->find((int) $id);

        return null !== $note && $note->usr === $context->user ? $note : null;
    }

    private function state(JmapContext $context): string
    {
        return $this->mapper->state(array_map(
            fn (Note $note): array => $this->mapper->note($note),
            $this->notes->findForUser($context->user),
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function objectMap(mixed $value, string $argument): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidArguments', sprintf('"%s" must be an object.', $argument));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectOf(mixed $value): array
    {
        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', 'Each create and update must be an object.');
        }

        return $value;
    }
}
