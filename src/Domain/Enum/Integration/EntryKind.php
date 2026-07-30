<?php

declare(strict_types=1);

namespace App\Domain\Enum\Integration;

/**
 * What a picker entry actually is, where "folder or file" is not enough.
 *
 * A person from Immich's face recognition is navigable like a folder but should
 * read like a portrait: a round preview with the name always under it, not a row
 * with a folder icon. Rather than teach the template to special-case a provider,
 * the driver says what it handed over.
 *
 * Entry::$kind is optional, so the drivers that only ever deal in files and
 * folders never mention this — kind() falls back to isFolder.
 */
enum EntryKind: string
{
    case File = 'file';
    case Folder = 'folder';

    /** A recognised face, or any other named subject worth browsing by. */
    case Person = 'person';
}
