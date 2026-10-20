<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * Why a send finally failed — what the warning on the draft and the toast say.
 *
 * Stored on the message (Message::$sendFailure) beside the moment it failed,
 * because the warning outlives the toast: a scheduled send fails with nobody
 * watching, and the Drafts list is where it is found afterwards (#125).
 *
 * Coarse on purpose. These are the distinctions the reader can act on — try
 * again, try later, look in Sent first — and not the provider's own status
 * codes, which say the same thing three different ways and are in the log.
 */
enum SendFailure: string
{
    /** The provider or server read the mail and refused it; sending it unchanged will not work. */
    case Refused = 'refused';

    /** The provider kept limiting sends for longer than a send waits (two minutes). */
    case RateLimited = 'rate_limited';

    /** The provider could not be reached, or kept failing on its side, for as long as the retries lasted. */
    case Unavailable = 'unavailable';

    /** The request went out and the answer was lost, and whether the mail arrived could not be checked. */
    case MayHaveSent = 'may_have_sent';

    /** Anything else that outlasted the retries — a token that would not refresh, say. */
    case Failed = 'failed';
}
