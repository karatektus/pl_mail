<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What the receiving mail server said about whether a message really comes
 * from the domain in its From line.
 *
 * Three answers, and the third is not a weaker version of either of the other
 * two. `Unknown` means nobody plMail has reason to believe said anything — the
 * account's server does not stamp its verdict on mail, or stamps it under a
 * name that cannot be tied to the account. It is the common case on a small
 * self-hosted IMAP server, and treating it as a failure would hold every
 * calendar update such a mailbox ever receives.
 *
 * See App\Service\Mail\SenderAuthentication for who is believed and why.
 */
enum SenderVerdict: string
{
    /** A believed server says the From domain is authentic. */
    case Pass = 'pass';

    /** A believed server looked and did not say so. */
    case Fail = 'fail';

    /** No believed server said anything. */
    case Unknown = 'unknown';
}
