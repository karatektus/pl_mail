<?php

declare(strict_types=1);

namespace App\Domain\Enum\Integration;

/**
 * What a "Save to…" lands in, which decides how the destination chooser is drawn
 * and what it calls the things it lists.
 *
 * Separate from Provider::isMediaLibrary() because the two questions only
 * coincided until Paperless arrived: that flag says "this holds photos", and the
 * chooser had been reading it as "this has no folder tree". Paperless holds
 * documents and has no tree either, so it was drawn as a tree its driver could
 * not walk.
 */
enum DestinationKind: string
{
    /** A tree: a folder can hold folders, so rows navigate and the footer saves "here". */
    case Folder = 'folder';

    /** A flat list of albums in a photo library. Each row is itself the choice. */
    case Album = 'album';

    /** A flat list of tags in a document archive. Each row is itself the choice. */
    case Tag = 'tag';

    /**
     * Whether there is nothing to descend into, so every listed container is a
     * choice rather than a place to browse to.
     */
    public function isFlat(): bool
    {
        return self::Folder !== $this;
    }
}
