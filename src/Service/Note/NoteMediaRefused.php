<?php

declare(strict_types=1);

namespace App\Service\Note;

/** A file a note will not take, with a message fit to show the person who sent it. */
final class NoteMediaRefused extends \RuntimeException
{
}
