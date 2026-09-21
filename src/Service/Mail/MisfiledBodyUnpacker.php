<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Opens a MIME container that webklex handed over as a single attachment.
 *
 * ── The shape ────────────────────────────────────────────────────────────────
 * A `multipart/related` holding EXACTLY ONE part — an HTML body with no inline
 * images beside it — is not descended into when it sits inside another
 * multipart. The whole container arrives as one attachment, named after its own
 * checksum, and the message has no body at all. Measured, not inferred:
 *
 *   alt > [plain, rel[html]]       html=EMPTY   attachment: multipart/related
 *   alt > [plain, rel[html,img]]   html=ok      attachment: image/png
 *   alt > [rel[html]]              html=EMPTY   attachment: multipart/related
 *   alt > [rel[html,img]]          html=ok      attachment: image/png
 *
 * The second column is the whole bug: add one inline image and the same message
 * parses correctly. Transactional mail that wraps its HTML in a `related` and
 * has no images to embed — a rejection from a recruiting system, say — lands in
 * the first row every time.
 *
 * ── Why re-parsing, rather than reaching for the text ────────────────────────
 * What was stored is a complete MIME block: delimiters, part headers, transfer
 * encoding, charset. Fishing the HTML out of it with a regular expression would
 * work on the easy ones and quietly mangle quoted-printable and anything not
 * UTF-8. Handing it back to the same library gets all of that right, and gets
 * the charset right in particular: inside a message of its own the HTML is a
 * BODY part, so webklex converts it — which is exactly the step it skips for an
 * attachment.
 *
 * ── The boundary ─────────────────────────────────────────────────────────────
 * MessagePart stores `content_type` without parameters, so the boundary is not
 * in the database. It does not have to be: a multipart body opens with its own
 * delimiter, so the first line IS the boundary with two dashes in front. That
 * is what makes an already-stored part repairable at all.
 */
final readonly class MisfiledBodyUnpacker
{
    /**
     * Open the container, or return null if it is not one this should touch.
     *
     * @return array{html: string, text: string}|null
     */
    public function unpack(string $contentType, string $raw): ?array
    {
        $boundary = $this->boundaryOf($raw);

        if (null === $boundary) {
            return null;
        }

        try {
            $message = ImapMessage::fromString(
                sprintf("Content-Type: %s; boundary=\"%s\"\r\nMIME-Version: 1.0\r\n\r\n%s", $contentType, $boundary, $raw),
                Config::make(['decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]]]),
            );
        } catch (Throwable) {
            return null;
        }

        // LEFT ALONE IF IT CARRIES FILES. The shape this exists for holds one
        // HTML part and nothing else, so anything with attachments inside it is
        // a container this has not seen and does not understand. Taking the
        // body and dropping the rest would turn a blank message into a blank
        // message that has also lost its attachments, which is worse than the
        // bug.
        if (count($message->getAttachments()) > 0) {
            return null;
        }

        $html = (string) $message->getHTMLBody();
        $text = (string) $message->getTextBody();

        if ('' === $html && '' === $text) {
            return null;
        }

        return ['html' => $html, 'text' => $text];
    }

    /**
     * The boundary a multipart body opens with, or null if it does not.
     *
     * Deliberately strict: a body that does not begin with a delimiter is not
     * a multipart body, whatever its content type claimed, and guessing at one
     * would hand the parser rubbish.
     */
    private function boundaryOf(string $raw): ?string
    {
        $first = strtok(ltrim($raw, "\r\n"), "\n");

        if (false === $first) {
            return null;
        }

        $first = rtrim($first, "\r");

        if (false === str_starts_with($first, '--') || '' === trim($first, '-')) {
            return null;
        }

        return substr($first, 2);
    }
}
