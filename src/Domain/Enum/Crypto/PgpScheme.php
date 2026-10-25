<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * How mail to one recipient is to be wrapped, once plMail sends encrypted
 * mail (phase 5 of issue #28).
 *
 * PGP/MIME (RFC 3156) is the default and the only form that carries
 * attachments and HTML intact. Inline PGP is for the few clients that still
 * cannot read anything else, and it is the person's call per contact.
 */
enum PgpScheme: string
{
    case Mime   = 'pgp-mime';
    case Inline = 'pgp-inline';
}
