<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\PgpMimeSplitter;
use PHPUnit\Framework\TestCase;

/**
 * What was signed comes out byte for byte as RFC 3156 defines it: the first
 * body part with its own headers, without the line break that belongs to the
 * boundary after it, and with CRLF line endings even where the message was
 * stored with bare LF.
 */
final class PgpMimeSplitterTest extends TestCase
{
    public function testTheSignedPartAndTheSignatureComeOutAsRfc3156DefinesThem(): void
    {
        $raw = "Content-Type: multipart/signed; micalg=pgp-sha256;\n"
            . " protocol=\"application/pgp-signature\"; boundary=\"S\"\n\n"
            . "This is an OpenPGP/MIME signed message.\n"
            . "--S\n"
            . "Content-Type: text/plain; charset=utf-8\n\n"
            . "Hello.\n"
            . "--S\n"
            . "Content-Type: application/pgp-signature\n\n"
            . "-----BEGIN PGP SIGNATURE-----\nabc\n-----END PGP SIGNATURE-----\n"
            . "--S--\n";

        self::assertSame(
            [
                'signed'    => "Content-Type: text/plain; charset=utf-8\r\n\r\nHello.",
                'signature' => "-----BEGIN PGP SIGNATURE-----\r\nabc\r\n-----END PGP SIGNATURE-----",
            ],
            PgpMimeSplitter::split($raw),
        );
    }

    public function testAMessageThatIsNotMultipartIsNotSplit(): void
    {
        self::assertNull(PgpMimeSplitter::split("Content-Type: text/plain\r\n\r\nHello.\r\n"));
    }
}
