<?php

declare(strict_types=1);

namespace App\Service\Contacts;

/**
 * A contact's photo as bytes: what ContactPhotoStore::read() hands a driver
 * to send, and what a driver hands back from the server.
 */
final readonly class ContactPhoto
{
    public function __construct(
        public string $bytes,
        public string $mime,
    ) {
    }

    /** JPEG, PNG or WEBP — the TYPE a vCard 3.0 PHOTO names. */
    public function extension(): string
    {
        return match ($this->mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpeg',
        };
    }
}
