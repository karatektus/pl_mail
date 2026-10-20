<?php

declare(strict_types=1);

namespace App\Service\Mail\Mime;

use InvalidArgumentException;
use Symfony\Component\Mime\Part\AbstractPart;

/**
 * The body of a message the browser built, carried onto the wire byte for byte.
 *
 * An OpenPGP signature covers the exact bytes of the entity it signs, and an
 * encrypted message is ciphertext nobody here could rebuild, so neither can go
 * through Symfony's own parts: they re-encode, re-fold and choose their own
 * boundaries. This part holds the entity — its own Content-Type and the rest
 * of its headers, a blank line, its body — and hands it back unchanged, while
 * Email keeps writing the message's outer headers (From, To, Subject,
 * Message-ID, Date …) the way it does for every other mail. Those sit outside
 * the signature and the encryption, which is what lets a scheduled send set
 * its Date when it leaves.
 *
 * The browser writes the entity with CRLF line ends and lines short enough for
 * SMTP (assets/crypto/mime.js); it is refused here if it is not even shaped
 * like one.
 */
final class SealedEntityPart extends AbstractPart
{
    private readonly string $mediaType;
    private readonly string $mediaSubtype;

    public function __construct(
        private readonly string $entity,
    ) {
        parent::__construct();

        $end = strpos($entity, "\r\n\r\n");

        if (false === $end || 1 !== preg_match('/^content-type:[ \t]*([a-z0-9!#$&^_.+-]+)\/([a-z0-9!#$&^_.+-]+)/im', substr($entity, 0, $end), $type)) {
            throw new InvalidArgumentException('Not a MIME entity: no Content-Type before the first blank line.');
        }

        $this->mediaType    = strtolower($type[1]);
        $this->mediaSubtype = strtolower($type[2]);
    }

    public function toString(): string
    {
        return $this->entity;
    }

    public function toIterable(): iterable
    {
        yield $this->entity;
    }

    public function bodyToString(): string
    {
        return substr($this->entity, (int) strpos($this->entity, "\r\n\r\n") + 4);
    }

    public function bodyToIterable(): iterable
    {
        yield $this->bodyToString();
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function getMediaSubtype(): string
    {
        return $this->mediaSubtype;
    }

    public function asDebugString(): string
    {
        return $this->mediaType . '/' . $this->mediaSubtype . ' (built in the browser)';
    }
}
