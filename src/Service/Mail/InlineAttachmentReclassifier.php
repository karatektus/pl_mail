<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\CalendarAttachment;
use App\Entity\Mail\Message;

/**
 * Re-decides, for a stored message, which of its parts are inline and which
 * are attachments, and whether the message has attachments at all.
 *
 * The rule is InlineAttachmentDetector's; this applies it to parts already in
 * the database, for the two callers that repair them: the upgrade task and
 * `app:attachments:reclassify`. One implementation, so the automatic repair
 * and the one an administrator runs cannot disagree about a part.
 *
 * Calendar parts keep what they have. GmailMessageBuilder stores an invite
 * whose bytes came in the payload as inline by its content type, not by the
 * detector, so the meeting shows as an invite card rather than a paperclip and
 * an "invite.ics" chip. Run through the detector, every such invite would come
 * out an attachment, since no body references it.
 *
 * Drafts are left alone entirely. Their parts were not guessed from a sender's
 * MIME: DraftAttachmentService stores an image pasted into the editor as
 * inline on purpose, and the body only names its cid once the next autosave
 * has run InlineImageRewriter::toCid(). A repair landing in between would
 * turn that picture into a chip and put a paperclip on the draft.
 *
 * Only the entities change; flushing, and the thread counters derived from
 * has_attachments, are the caller's.
 */
final readonly class InlineAttachmentReclassifier
{
    public function __construct(
        private InlineAttachmentDetector $detector,
    ) {}

    /**
     * @return int how many parts changed side
     */
    public function reclassify(Message $message): int
    {
        if (true === $message->isDraft()) {
            return 0;
        }

        $changed        = 0;
        $hasAttachments = false;

        foreach ($message->messageParts as $part) {
            $isInline = true === in_array(strtolower((string) $part->contentType), CalendarAttachment::CONTENT_TYPES, true)
                ? true === $part->isInline
                : $this->detector->isInline($part->contentId, $message->bodyHtml);

            if ($isInline !== $part->isInline) {
                $part->isInline    = $isInline;
                $part->disposition = $isInline ? 'inline' : 'attachment';
                ++$changed;
            }

            if (false === $isInline) {
                $hasAttachments = true;
            }
        }

        $message->hasAttachments = $hasAttachments;

        return $changed;
    }
}
