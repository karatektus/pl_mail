<?php

declare(strict_types=1);

namespace App\Service\Note;

use App\Entity\Note\Note;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * One of a note's files as a response, for the web page, the share page and
 * the API alike: its own type, inline, never sniffed, privately cached.
 */
final readonly class NoteMediaResponder
{
    public function __construct(private NoteMediaStorage $storage)
    {
    }

    public function respond(Note $note, string $id): ?Response
    {
        $entry = 1 === preg_match('/^[a-f0-9]{24}$/', $id) ? $this->storage->find($note, $id) : null;

        if (null === $entry || false === is_file($this->storage->path($note, $id))) {
            return null;
        }

        $response = new BinaryFileResponse($this->storage->path($note, $id));
        $response->headers->set('Content-Type', (string) $entry['type']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->setPrivate();
        $response->setMaxAge(3600);
        $name = (string) $entry['name'];
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            str_replace(['/', '\\'], '_', $name),
            (string) (preg_replace('/[^\x20-\x7e]|[\/\\\\%]/', '_', $name) ?: 'media'),
        );

        return $response;
    }
}
