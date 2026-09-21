<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Recognises a message body that webklex handed over as an attachment.
 *
 * ── The bug this exists for ──────────────────────────────────────────────────
 * Webklex decides body-or-attachment in Part::isAttachment(), and its rule for
 * a text part is "no disposition, or a disposition that is not one of ours".
 * A part that says `Content-Disposition: inline` AND carries a Content-ID
 * falls past that rule, and so does one that says `Content-Disposition:
 * attachment` with no filename on it. Either way the part never reaches
 * Message::fetchPart()'s body branch: getHTMLBody() returns null, and the
 * body turns up in getAttachments() instead.
 *
 * What that looks like on screen is a message with no body at all and one
 * mysterious attachment named something like `09704cea` — which is not a name
 * at all. Webklex fills an absent filename with `hash("crc32c", …)` of the
 * part, and eight hex characters is what that hash is. THAT is the tell, and
 * it is the whole of the test below: a part with no name of its own is not an
 * attachment a person ever made. A genuinely attached `Rechnung.html` has a
 * filename and is left exactly where it is.
 *
 * Senders that do this are ordinary — Workday, and the rest of the
 * transactional-mail estate that stamps `Content-Disposition: inline` on the
 * body and a Content-ID beside it. Gmail renders those messages without
 * comment, which is what makes plMail showing nothing look like plMail losing
 * the mail.
 *
 * ── Why not fix it in the library ────────────────────────────────────────────
 * Webklex's rule is not obviously wrong on its own terms — a text part marked
 * `attachment` IS an attachment by the letter of RFC 2183. It is wrong about
 * what senders actually do, and that judgement belongs to this application
 * rather than to a patched vendor tree that the next `composer update`
 * discards.
 *
 * Two shapes are reclaimed. A bare text/plain or text/html part is the body
 * itself. A `multipart/*` is a CONTAINER holding one — webklex hands over the
 * whole block when a `multipart/related` has a single part in it — and has to
 * be opened by MisfiledBodyUnpacker before anything can be done with it.
 *
 * text/calendar is deliberately NOT reclaimed: an invite is neither a body nor
 * a user-facing attachment, and it has its own path on both the IMAP and the
 * Gmail side.
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
     * Whether this "attachment" is really the message body.
     *
     * @param string|null $filename what webklex reports as the part's filename
     * @param string|null $hash     Attachment::getHash(), which webklex uses as
     *                              the filename when the part had none — so the
     *                              two being equal IS "this part is unnamed"
     */
    public function isBody(?string $contentType, ?string $filename, ?string $hash): bool
    {
        $type = $this->normalise($contentType);

        if (false === in_array($type, self::BODY_TYPES, true) && false === $this->isContainer($type)) {
            return false;
        }

        $filename = (string) $filename;

        if ('' === $filename) {
            return true;
        }

        return $filename === (string) $hash;
    }

    /**
     * Whether a STORED part's filename is really webklex's hash standing in
     * for one — the same judgement as isBody(), made after the fact.
     *
     * At ingest the hash is in hand and the test is exact. A MessagePart row
     * does not keep it, so the repair has to recognise the shape instead:
     * crc32c is four bytes, written as eight lowercase hex characters, and a
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
     * webklex hands over a whole `multipart/related` when it holds a single
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
     * The bare type, without the parameters webklex sometimes leaves on it.
     */
    private function normalise(?string $contentType): string
    {
        return strtolower(trim(explode(';', (string) $contentType)[0]));
    }
}
