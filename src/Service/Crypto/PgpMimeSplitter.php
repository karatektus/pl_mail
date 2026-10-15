<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Takes a PGP/MIME signed message apart into the bytes that were signed and
 * the signature, as RFC 3156 §5 defines them.
 *
 * By hand, from the raw source, and not through a MIME parser: what was signed
 * is the first body part *exactly as it was sent* — its headers, its line
 * breaks, its transfer encoding — and every parser hands back something
 * decoded. One changed byte and a correct signature reads as forged. The only
 * change made is the one the RFC itself requires of a verifier: line endings
 * made canonical (CRLF), since a mail server along the way may have stored
 * them as bare LF.
 */
final class PgpMimeSplitter
{
    /**
     * @return array{signed: string, signature: string}|null null when the
     *         message is not a two-part multipart/signed with a boundary
     */
    public static function split(string $raw): ?array
    {
        $raw = (string) preg_replace("/\r?\n/", "\r\n", $raw);
        $end = strpos($raw, "\r\n\r\n");

        if (false === $end) {
            return null;
        }

        $headers = (string) preg_replace("/\r\n[ \t]+/", ' ', substr($raw, 0, $end));
        $body    = substr($raw, $end + 4);

        if (1 !== preg_match('/^content-type:[^\r\n]*boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/im', $headers, $match)) {
            return null;
        }

        $boundary = '' !== $match[1] ? $match[1] : $match[2];
        $parts    = preg_split('/(?:^|\r\n)--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*(?=\r\n|$)/', $body);

        // Preamble, signed part, signature part, epilogue.
        if (false === $parts || count($parts) < 3) {
            return null;
        }

        $signed    = (string) preg_replace('/^\r\n/', '', $parts[1]);
        $signature = (string) preg_replace('/^\r\n/', '', $parts[2]);
        $bodyStart = strpos($signature, "\r\n\r\n");

        if ('' === $signed || false === $bodyStart) {
            return null;
        }

        return ['signed' => $signed, 'signature' => substr($signature, $bodyStart + 4)];
    }
}
