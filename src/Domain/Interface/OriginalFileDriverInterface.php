<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;

/**
 * A driver whose download() is not the file the listing described.
 *
 * Paperless-ngx is the one: its listing reports the original's type, while
 * download() answers with the archived PDF it made from it, which is the copy
 * worth attaching to a mail. For something that has to be the original — a
 * profile picture taken from a scanned photograph — that PDF is the wrong file,
 * under a type the listing never promised.
 *
 * Separate from IntegrationDriverInterface for the same reason
 * SearchableDriverInterface is: every other driver has one file per id, and
 * folding this in would make six of them carry a method that repeats download().
 */
interface OriginalFileDriverInterface
{
    /**
     * The file as it was put into the service, not as the service rendered it.
     *
     * @throws IntegrationException
     */
    public function downloadOriginal(Integration $integration, string $fileId): RemoteFile;
}
