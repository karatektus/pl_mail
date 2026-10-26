<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Entity\Mail\Message;

/**
 * Tells an OpenPGP or S/MIME message from a plain one, as it is ingested.
 *
 * One class for the three providers, because they see the same message three
 * ways and must reach the same answer. IMAP and Gmail hand over the top-level
 * Content-Type with its parameters, and that is what decides it: RFC 3156 and
 * RFC 8551 put the whole story in `protocol=` and `smime-type=`. Graph may not
 * — its headers are a selection, and Exchange is known to turn a signed S/MIME
 * message into a single `smime.p7m` attachment — so the attachment types are
 * the fallback. Inline PGP is the armored block in the text body, and is the
 * last thing checked.
 *
 * What a builder does with the answer is in seal(): an encrypted message's
 * body is blanked before the row is written, and the parts that are only the
 * wrapping — the signature, the ciphertext, the "Version: 1" control part —
 * are left out instead of shown as attachments.
 *
 * The parameters are parsed here rather than read from webklex's extension
 * attributes: those are first-come across every header of the message (a DKIM
 * or ARC header can claim `protocol` first), and the lookup turns `-` into `_`,
 * so `smime-type` is never found under its own name.
 */
final readonly class MessageCryptoDetector
{
    private const array PKCS7_MIME      = ['application/pkcs7-mime', 'application/x-pkcs7-mime'];
    private const array PKCS7_SIGNATURE = ['application/pkcs7-signature', 'application/x-pkcs7-signature'];

    /**
     * @param list<array{type: string, name: ?string}> $parts the attachment
     *        parts the provider found, for when the headers do not say
     */
    public function classify(?string $contentType, ?string $text, ?string $html, array $parts = []): ?MessageCrypto
    {
        [$type, $params] = self::parse((string) $contentType);

        $fromStructure = match (true) {
            'multipart/encrypted' === $type && 'application/pgp-encrypted' === ($params['protocol'] ?? null)
                => MessageCrypto::PgpEncrypted,
            'multipart/signed' === $type && 'application/pgp-signature' === ($params['protocol'] ?? null)
                => MessageCrypto::PgpSigned,
            'multipart/signed' === $type && true === in_array($params['protocol'] ?? null, self::PKCS7_SIGNATURE, true)
                => MessageCrypto::SmimeSigned,
            true === in_array($type, self::PKCS7_MIME, true)
                => self::smimeKind($params['smime-type'] ?? null),
            default => null,
        };

        return $fromStructure
            ?? self::fromParts($parts)
            ?? self::fromArmor((string) $text)
            ?? self::fromArmor(strip_tags((string) $html));
    }

    /**
     * Whether a part is only the wrapping, which nobody should be offered as
     * an attachment. Everything of an encrypted message is: what it carries
     * is inside the ciphertext, and the browser opens it from the raw source.
     */
    public function isWrapping(MessageCrypto $crypto, ?string $contentType, ?string $name = null): bool
    {
        if (true === $crypto->isEncrypted()) {
            return true;
        }

        $type = self::parse((string) $contentType)[0];
        $name = strtolower((string) $name);

        return match ($crypto) {
            MessageCrypto::PgpSigned   => 'application/pgp-signature' === $type,
            MessageCrypto::SmimeSigned => true === in_array($type, self::PKCS7_SIGNATURE, true)
                || true === in_array($type, self::PKCS7_MIME, true)
                || 'smime.p7s' === $name
                || 'smime.p7m' === $name,
            default                    => false,
        };
    }

    /**
     * Records what the message is, and blanks the body of an encrypted one.
     *
     * Blanked rather than kept: what is there is ciphertext, or for inline PGP
     * the armored block, and search, rules, snippets and the assistant would
     * all take it for text. The raw source still has it, which is where the
     * browser decrypts from.
     */
    public function seal(Message $message, ?MessageCrypto $crypto): void
    {
        $message->crypto = $crypto;

        if (null !== $crypto && true === $crypto->isEncrypted()) {
            $message->bodyText = '';
            $message->bodyHtml = '';
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Opaque S/MIME: enveloped data is encrypted, signed data is signed. A
     * missing smime-type is read as encrypted — the more careful of the two,
     * since it keeps the body off the server, and an opaque signed message has
     * no readable body here either.
     */
    private static function smimeKind(?string $smimeType): MessageCrypto
    {
        return 'signed-data' === $smimeType ? MessageCrypto::SmimeSigned : MessageCrypto::SmimeEncrypted;
    }

    /**
     * The attachments, for a provider whose headers did not say. Strongest
     * first: a part list can hold a signature beside ciphertext, and the
     * message is then encrypted, not merely signed.
     *
     * @param list<array{type: string, name: ?string}> $parts
     */
    private static function fromParts(array $parts): ?MessageCrypto
    {
        $types = [];
        $names = [];

        foreach ($parts as $part) {
            $types[] = self::parse($part['type'])[0];
            $names[] = strtolower((string) $part['name']);
        }

        return match (true) {
            true === in_array('application/pgp-encrypted', $types, true) => MessageCrypto::PgpEncrypted,
            // Exchange's rendering of a signed S/MIME message: the whole
            // multipart/signed body as one attachment named smime.p7m.
            true === in_array('multipart/signed', $types, true) && true === in_array('smime.p7m', $names, true) => MessageCrypto::SmimeSigned,
            [] !== array_intersect(self::PKCS7_MIME, $types) || true === in_array('smime.p7m', $names, true) => MessageCrypto::SmimeEncrypted,
            [] !== array_intersect(self::PKCS7_SIGNATURE, $types) => MessageCrypto::SmimeSigned,
            true === in_array('application/pgp-signature', $types, true) => MessageCrypto::PgpSigned,
            default => null,
        };
    }

    /**
     * Inline PGP. Anchored to the start of a line, so a message that quotes
     * an armor header in a sentence about PGP is not taken for one.
     */
    private static function fromArmor(string $text): ?MessageCrypto
    {
        return match (true) {
            1 === preg_match('/^-----BEGIN PGP MESSAGE-----\R/m', $text)        => MessageCrypto::PgpInlineEncrypted,
            1 === preg_match('/^-----BEGIN PGP SIGNED MESSAGE-----\R/m', $text) => MessageCrypto::PgpInlineSigned,
            default                                                            => null,
        };
    }

    /**
     * A Content-Type value split into its lower-cased type and parameters.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function parse(string $value): array
    {
        $pieces = explode(';', $value);
        $type   = strtolower(trim(array_shift($pieces)));
        $params = [];

        // Folded headers and quoted values: `protocol="application/pgp-signature"`,
        // `smime-type=enveloped-data`, possibly with whitespace around `=`.
        preg_match_all('/([a-z0-9_-]+)\s*=\s*("([^"]*)"|[^;\s]+)/i', implode(';', $pieces), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $params[strtolower($match[1])] = strtolower('' !== ($match[3] ?? '') ? $match[3] : trim($match[2], '"'));
        }

        return [$type, $params];
    }
}
