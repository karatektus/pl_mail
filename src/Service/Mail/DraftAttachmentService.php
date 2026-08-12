<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The files hanging off a draft: what may be attached, where the bytes go, and
 * what happens to them when a part or the whole draft is dropped.
 *
 * Extracted from ComposeController, which knew all of it — the size ceiling,
 * the storage bucket layout, the flag that has to follow the parts around and
 * the JMAP announcement each change owes its clients. None of that is about
 * answering an HTTP request; the controller keeps the part that is, which is
 * turning a refusal into a response the compose window can show.
 *
 * Every method here leaves the draft flushed, because the attachment strip the
 * window swaps in is rendered from what is on the message afterwards.
 */
final readonly class DraftAttachmentService
{
    /**
     * Per-file ceiling for compose attachments. The compose window reads it too
     * (via ComposeController::MAX_ATTACHMENT_BYTES) so it can refuse an
     * oversized file before it is uploaded.
     *
     * Must stay under upload_max_filesize (frankenphp/conf.d/10-app.ini).
     */
    public const int MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private EntityManagerInterface  $entityManager,
        private AttachmentStorageHelper $storage,
        private MailChangeRecorder      $changes,
        private TranslatorInterface     $translator,
    ) {
    }

    /**
     * Attach uploaded files to a draft, or say why they cannot be attached.
     *
     * @param list<UploadedFile> $files
     *
     * @return string|null the reason nothing was attached, short enough for the
     *                     window's status line, or null when all of them were
     */
    public function attach(Message $message, array $files): ?string
    {
        // Everything is checked before anything is stored, so a rejected file
        // cannot leave the ones before it attached.
        foreach ($files as $file) {
            $error = $this->refusal($file);

            if (null !== $error) {
                return $error;
            }
        }

        foreach ($files as $file) {
            // Bucketed like synced attachments: account / mailbox (0 where the
            // account has none) / message. Drafts have no UID, so the message
            // id keeps one draft's files out of another's directory.
            $storagePath = $this->storage->store(
                (int) $message->account->id,
                (int) ($message->mailbox->id ?? 0),
                (int) $message->id,
                (string) $file->getClientOriginalName(),
                (string) file_get_contents($file->getPathname()),
            );

            $part = new MessagePart();
            $part->message = $message;
            // Guessed from the bytes, not from the client's header — this
            // value comes back out as a Content-Type on download.
            $part->contentType = $file->getMimeType() ?? 'application/octet-stream';
            $part->filename    = basename((string) $file->getClientOriginalName());
            $part->disposition = 'attachment';
            $part->size        = $file->getSize();
            $part->storagePath = $storagePath;
            $part->isInline    = false;

            $message->addMessagePart($part);
            $this->entityManager->persist($part);
        }

        $this->syncFlag($message);
        $this->announce($message);
        $this->entityManager->flush();

        return null;
    }

    /**
     * Store an image as part of the body rather than as an attachment.
     *
     * Same bytes, same bucket, same ceiling as attach() — what differs is what
     * the part claims to be: an inline disposition, a content id for the body
     * to reference it by, and isInline, which is what keeps syncFlag() from
     * putting a paperclip on a mail whose only "attachment" is a picture the
     * reader can already see.
     *
     * The content id is minted here rather than by the caller because it is
     * the part's identity on the wire: MessageSendService::buildEmail() embeds
     * the file under it, and InlineImageRewriter rewrites the body's <img> to
     * match. Two halves of one reference, so one place decides it.
     *
     * @return MessagePart|string the stored part, or the reason it was refused
     */
    public function attachInline(Message $message, UploadedFile $file): MessagePart|string
    {
        $error = $this->refusal($file);

        if (null !== $error) {
            return $error;
        }

        // Guessed from the bytes, never from the client's header: this decides
        // whether the upload is allowed at all, and a text/html file announcing
        // itself as image/png must not become part of a mail body.
        $contentType = $file->getMimeType() ?? 'application/octet-stream';

        if (false === str_starts_with($contentType, 'image/')) {
            return $this->translator->trans('compose.upload.images_only');
        }

        $storagePath = $this->storage->store(
            (int) $message->account->id,
            (int) ($message->mailbox->id ?? 0),
            (int) $message->id,
            (string) $file->getClientOriginalName(),
            (string) file_get_contents($file->getPathname()),
        );

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = $contentType;
        $part->filename    = basename((string) $file->getClientOriginalName());
        $part->disposition = 'inline';
        $part->size        = $file->getSize();
        $part->storagePath = $storagePath;
        $part->isInline    = true;
        $part->contentId   = bin2hex(random_bytes(12)) . '@plmail';

        $message->addMessagePart($part);
        $this->entityManager->persist($part);

        // Derived, not assumed: an inline part leaves the flag exactly where
        // the draft's real attachments left it.
        $this->syncFlag($message);
        $this->announce($message);
        $this->entityManager->flush();

        return $part;
    }

    /** Detach one file and delete its bytes. */
    public function remove(MessagePart $part): void
    {
        $message = $part->message;

        $this->storage->delete($part->storagePath);

        $message->removeMessagePart($part);
        $this->entityManager->remove($part);

        $this->syncFlag($message);
        $this->announce($message);
        $this->entityManager->flush();
    }

    /** Drop the files a discarded draft uploaded; the rows cascade. */
    public function deleteStoredFiles(Message $message): void
    {
        foreach ($message->messageParts as $part) {
            $this->storage->delete($part->storagePath);
        }
    }

    /**
     * Keep the attachment flag in step with the parts actually stored.
     *
     * Autosave runs on every keystroke and goes through here, which is why the
     * flag is derived rather than assigned: hardcoding false used to wipe it
     * off a draft that had files attached to it.
     */
    public function syncFlag(Message $message): void
    {
        $hasAttachments = false;

        foreach ($message->messageParts as $part) {
            if (false === (bool) $part->isInline) {
                $hasAttachments = true;

                break;
            }
        }

        $message->hasAttachments = $hasAttachments;
    }

    /**
     * Adding or dropping a file rewrites Email.attachments and
     * Email.hasAttachment, both of which JMAP publishes — so a client left
     * untold keeps showing the draft with the files it had at last sync.
     *
     * Never a create: the window forces a save before it accepts an upload, so
     * the row and its conversation both exist by the time anything gets here,
     * and the announcement can sit ahead of the caller's flush and ride out on
     * it.
     */
    private function announce(Message $message): void
    {
        $this->changes->emailChanged(
            (int) $message->account->id,
            (string) $message->id,
            created: false,
            thread: $message->thread,
        );
    }

    /**
     * Why this upload cannot be attached, or null when it can.
     *
     * Translated here rather than at the controller, because the reason is the
     * whole of the answer: the compose window prints this string as-is on its
     * status line. It used to be spelled in English in this method, which made
     * a refused upload the one thing a German window said in English.
     */
    private function refusal(UploadedFile $file): ?string
    {
        if (UPLOAD_ERR_INI_SIZE === $file->getError() || UPLOAD_ERR_FORM_SIZE === $file->getError()) {
            return $this->translator->trans('compose.upload.too_large');
        }

        if (false === $file->isValid()) {
            return $this->translator->trans('compose.upload.failed');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $this->translator->trans('compose.upload.over_limit', [
                '%limit%' => intdiv(self::MAX_BYTES, 1024 * 1024),
            ]);
        }

        return null;
    }
}
