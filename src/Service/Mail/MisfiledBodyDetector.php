<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Recognises, in mail stored before #82, a message body that webklex handed
 * over as an attachment.
 *
 * ── The bug this exists for ──────────────────────────────────────────────────
 * Webklex decided body-or-attachment in Part::isAttachment(), and its rule for
 * a text part was "no disposition, or a disposition that is not one of ours".
 * A part that said `Content-Disposition: inline` AND carried a Content-ID fell
 * past that rule, and so did one that said `Content-Disposition: attachment`
 * with no filename on it. Either way the body turned up as an attachment, and
 * the message had none.
 *
 * What that looked like on screen is a message with no body at all and one
 * mysterious attachment named something like `09704cea` — which is not a name
 * at all. Webklex filled an absent filename with `hash("crc32c", …)` of the
 * part, and eight hex characters is what that hash is. THAT is the tell: a
 * part with no name of its own is not an attachment a person ever made. A
 * genuinely attached `Rechnung.html` has a filename and is left where it is.
 *
 * ── Only for what is already stored ──────────────────────────────────────────
 * MessageParser reads these parts as the bodies they are, so nothing new
 * arrives this way and the sync no longer asks. What is left is the mail
 * stored while webklex parsed, which MisfiledBodyBackfillTask repairs from the
 * bytes on disk, and that is the one caller.
 *
 * Two shapes are reclaimed. A bare text/plain or text/html part is the body
 * itself. A `multipart/*` is a CONTAINER holding one — webklex handed over the
 * whole block when a `multipart/related` had a single part in it — and has to
 * be opened by MisfiledBodyUnpacker before anything can be done with it.
 *
 * text/calendar is deliberately NOT reclaimed: an invite is neither a body nor
 * a user-facing attachment, and it has its own path.
 */
final class MisfiledBodyDetector
{
    /**
     * The only two types a missing body can have come out of.
     *
     * @var list<string>
     */
    private const array BODY_TYPES = ['text/plain', 'text/html'];

    /**
     * Whether a part of this type can be a body webklex misfiled: text, or a
     * container that holds text.
     */
    public function isBodyType(?string $contentType): bool
    {
        $type = $this->normalise($contentType);

        return true === in_array($type, self::BODY_TYPES, true) || true === $this->isContainer($type);
    }

    /**
     * Whether a STORED part's filename is really webklex's hash standing in
     * for one.
     *
     * A MessagePart row does not keep the hash, so the repair has to
     * recognise the shape instead: crc32c is four bytes, written as eight lowercase hex characters, and a
     * file a person attached is not called that. The backfill pairs this with
     * "and the body of that type is empty", which is what carries the
     * remaining doubt.
     */
    public function looksLikeStoredHash(?string $filename): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}$/', (string) $filename);
    }

    /**
     * Whether this nameless part is a MIME CONTAINER rather than a body.
     *
     * webklex handed over a whole `multipart/related` when it held a single
     * part — see MisfiledBodyUnpacker, which measures the exact shapes. The
     * content is then a MIME block rather than text, so it cannot go into a
     * body column as it stands; it has to be opened first.
     */
    public function isContainer(?string $contentType): bool
    {
        return str_starts_with($this->normalise($contentType), 'multipart/');
    }

    /**
     * Which body a reclaimed part belongs in: 'html' or 'text'.
     */
    public function slotFor(?string $contentType): string
    {
        return 'text/html' === $this->normalise($contentType) ? 'html' : 'text';
    }

    /**
     * The bare type, without any parameters left on it.
     */
    private function normalise(?string $contentType): string
    {
        return strtolower(trim(explode(';', (string) $contentType)[0]));
    }
}
