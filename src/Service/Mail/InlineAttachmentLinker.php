<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\InlineDisposition;
use App\Entity\Mail\Message;

/**
 * Points a message's own inline images at their signed URL, at render time.
 *
 * MailBodySanitizer turns `cid:` into `/mail/attachment/{id}` at ingest, and
 * that route needs a session the sandboxed reading frame cannot send. This
 * rewrites those references on the way to the template, so it also repairs
 * every message already in the database without a backfill, and a rotated
 * secret never leaves a stale signature stored in a body.
 *
 * WHY ONLY THE MESSAGE'S OWN PARTS
 * --------------------------------
 * The stored body is the sender's HTML. RelativeUrlAttributeSanitizer lets any
 * `/mail/attachment/<digits>` through, because it cannot know which ids belong
 * to this message, so a sender can write the id of somebody else's attachment.
 * Signing every id found in the body would turn that into a capability for it.
 * The map is built from {@see Message::$messageParts} instead, so an id that
 * is not one of this message's parts is left exactly as it was, still behind
 * the login.
 *
 * Only raster types on the {@see InlineDisposition} allow-list are signed. A
 * part that is not an image stays on the authenticated route, where a click
 * from the top-level page (with a cookie) still downloads it.
 */
final readonly class InlineAttachmentLinker
{
    public function __construct(
        private InlineAttachmentSigner $signer,
    ) {
    }

    public function link(?string $html, Message $message): ?string
    {
        if (null === $html || false === str_contains($html, '/mail/attachment/')) {
            return $html;
        }

        $signable = [];

        foreach ($message->messageParts as $part) {
            if (null === $part->id || false === InlineDisposition::allows($part->contentType ?? '')) {
                continue;
            }

            $signable[$part->id] = true;
        }

        if ([] === $signable) {
            return $html;
        }

        // Anchored on the delimiter a URL sits inside (`src="…"`, `url(…)`) and
        // on the digits ending right there, so `/mail/attachment/12/preview` or
        // `/mail/attachment/12?download=1` are not touched.
        return (string) preg_replace_callback(
            '#(["\'(])/mail/attachment/(\d+)(?=["\')])#',
            fn (array $m): string => true === isset($signable[(int) $m[2]])
                ? $m[1] . $this->signer->urlFor((int) $m[2])
                : $m[0],
            $html,
        );
    }
}
