<?php

declare(strict_types=1);

namespace App\Service\Note;

use App\Entity\Note\Note;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The files behind a note's pictures, drawings and voice recordings.
 *
 * On the uploads volume, under a directory of their own beside avatars and
 * signatures: `<storage>/uploads/notes/<user>/<note>/<id>`. The weekly blob
 * prune walks only the numbered per-account trees, so it never touches it. The
 * list of them is the note's `media` column; this class keeps the two in step
 * and refuses what does not belong in a note — anything but an image or an
 * audio recording, anything too large, more than MAX_ITEMS of them.
 */
final readonly class NoteMediaStorage
{
    public const array KINDS = ['image', 'drawing', 'audio'];

    public const int MAX_ITEMS = 30;

    /** Per file, by kind. */
    public const array MAX_BYTES = ['image' => 15 * 1024 * 1024, 'drawing' => 10 * 1024 * 1024, 'audio' => 25 * 1024 * 1024];

    private const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];

    private const array AUDIO_TYPES = [
        'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/aac', 'audio/x-m4a', 'audio/m4a',
        'audio/wav', 'audio/x-wav', 'audio/3gpp', 'audio/amr', 'video/webm', 'video/mp4',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(APP_STORAGE_DIR)%')]
        private string $storageDir,
    ) {
    }

    /**
     * Adds a file to the note: validates it, moves it into place and appends
     * it to `media`. The caller flushes. Answers the new entry.
     *
     * @return array{id: string, kind: string, name: string, type: string, size: int, createdAt: string}
     *
     * @throws NoteMediaRefused
     */
    public function add(Note $note, string $kind, string $name, string $type, string $sourcePath): array
    {
        if (false === \in_array($kind, self::KINDS, true)) {
            throw new NoteMediaRefused('Unknown kind; use image, drawing or audio.');
        }

        if (\count($note->media) >= self::MAX_ITEMS) {
            throw new NoteMediaRefused(sprintf('A note holds at most %d pictures and recordings.', self::MAX_ITEMS));
        }

        $type = strtolower(trim(explode(';', $type)[0]));
        $allowed = 'audio' === $kind ? self::AUDIO_TYPES : self::IMAGE_TYPES;
        if (false === \in_array($type, $allowed, true)) {
            throw new NoteMediaRefused('audio' === $kind ? 'That is not an audio recording.' : 'That is not a picture.');
        }

        $size = (int) @filesize($sourcePath);
        if ($size <= 0) {
            throw new NoteMediaRefused('The file is empty.');
        }
        if ($size > self::MAX_BYTES[$kind]) {
            throw new NoteMediaRefused(sprintf('Files of this kind are limited to %d MB.', intdiv(self::MAX_BYTES[$kind], 1024 * 1024)));
        }

        $id   = bin2hex(random_bytes(12));
        $path = $this->path($note, $id);
        $dir  = \dirname($path);

        if (false === is_dir($dir) && false === @mkdir($dir, 0750, true) && false === is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create %s', $dir));
        }
        if (false === @rename($sourcePath, $path)) {
            if (false === @copy($sourcePath, $path)) {
                throw new \RuntimeException(sprintf('Could not write %s', $path));
            }
            @unlink($sourcePath);
        }
        @chmod($path, 0640);

        $name = mb_substr(trim((string) preg_replace('/[\x00-\x1f\/\\\\]/u', '_', $name)), 0, 120);
        $entry = [
            'id'        => $id,
            'kind'      => $kind,
            'name'      => '' !== $name ? $name : $kind,
            'type'      => $type,
            'size'      => $size,
            'createdAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];

        $note->media = [...array_values($note->media), $entry];

        return $entry;
    }

    /** The entry with this id, or null. */
    public function find(Note $note, string $id): ?array
    {
        foreach ($note->media as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** Takes one off the note and deletes its file. The caller flushes. */
    public function remove(Note $note, string $id): bool
    {
        $kept = array_values(array_filter($note->media, static fn (array $e): bool => ($e['id'] ?? null) !== $id));

        if (\count($kept) === \count($note->media)) {
            return false;
        }

        $note->media = $kept;
        $path        = $this->path($note, $id);
        if (is_file($path)) {
            @unlink($path);
        }

        return true;
    }

    /** Deletes every file of a note that is being destroyed. */
    public function removeAll(Note $note): void
    {
        $dir = $this->directory($note);

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function path(Note $note, string $id): string
    {
        if (1 !== preg_match('/^[a-f0-9]{24}$/', $id)) {
            throw new \InvalidArgumentException('Malformed media id.');
        }

        return $this->directory($note) . '/' . $id;
    }

    private function directory(Note $note): string
    {
        return sprintf('%s/%s/uploads/notes/%d/%d', $this->projectDir, $this->storageDir, $note->usr->id, $note->id);
    }
}
