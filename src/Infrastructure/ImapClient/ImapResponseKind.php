<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

enum ImapResponseKind
{
    /** `A12 OK …` — the end of the command tagged A12. */
    case Tagged;

    /** `* …` — data, or a status the server volunteers. */
    case Untagged;

    /** `+ …` — the server waiting for the rest of a command. */
    case Continuation;
}
