<?php

declare(strict_types=1);

namespace App\Jmap\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Jmap\Blob\BlobId;
use App\Jmap\Blob\BlobResolver;
use App\Jmap\Protocol\Exception\MethodException;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\DraftPersister;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a JMAP Email/set "create" or "update" object into a persisted plMail
 * draft.
 *
 * What a draft is — Drafts label, mailbox pointer, sanitised body, threading,
 * thread-label resync — is DraftPersister's, shared with the web composer.
 * Every draft an app created once went missing from the Drafts list because
 * this class was a copy of the controller's version that had drifted one line
 * behind it, so it is deliberately no longer a copy of anything.
 *
 * What is left here is the protocol: reading a JMAP Email object, refusing the
 * ones that are malformed, and turning uploaded blobs into attachments.
 */
final class JmapDraftWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailBodySanitizer $bodySanitizer,
        private readonly BlobResolver $blobResolver,
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly DraftPersister $drafts,
        private readonly DraftAttachmentService $draftAttachments,
    ) {
    }

    /**
     * @param array<string,mixed> $create the JMAP Email object being created
     */
    public function create(Account $account, array $create): Message
    {
        $message = new Message();
        $message->account = $account;
        $message->subject = $this->stringOrNull($create['subject'] ?? null, 'subject');
        $message->toAddresses = $this->addresses($create['to'] ?? null, 'to');
        $message->ccAddresses = $this->addresses($create['cc'] ?? null, 'cc');
        $message->bccAddresses = $this->addresses($create['bcc'] ?? null, 'bcc');
        $message->bodyHtml = $this->body($create);

        $this->applyReplyContext($message, $create);
        $this->drafts->fileUnderAccount($message, $account);
        $this->persistDraft($message, $account);
        // After the flush, not before: the storage path is bucketed by message
        // id, and a draft has none until it is persisted. This mirrors the web
        // composer, which forces a save before it will accept an upload.
        $this->applyAttachments($message, $account, $create);

        return $message;
    }

    /**
     * Rewrites an existing draft in place.
     *
     * Only the properties the patch actually named. A composer that sends the
     * whole object every save is normal, but so is one that sends just the
     * body, and treating an absent key as "clear this" would silently drop a
     * subject the user never touched.
     *
     * The message keeps its id, its thread and its place in the Drafts label,
     * so a draft edited from three devices stays one draft — which is what lets
     * a phone composer attach a file to a draft it autosaved a minute ago
     * instead of recreating it under a new id.
     *
     * A named property is a whole value, though: `attachments` carries the
     * complete set the draft should end up with, not an addition to it. See
     * planAttachments().
     *
     * @param array<string,mixed> $patch already filtered to draft properties
     */
    public function update(Account $account, Message $message, array $patch): void
    {
        // Worked out before anything is written, and applied last. Resolving a
        // blob is the only part of an update that can fail on input the client
        // could not check for itself, and a client told "notUpdated" has to be
        // able to assume the draft is as it was — finding the subject from the
        // same patch applied anyway leaves it unable to say what it now holds.
        $attachments = true === array_key_exists('attachments', $patch)
            ? $this->planAttachments($message, $account, $patch['attachments'])
            : null;

        if (true === array_key_exists('subject', $patch)) {
            $message->subject = $this->stringOrNull($patch['subject'], 'subject');
        }

        foreach (['to' => 'toAddresses', 'cc' => 'ccAddresses', 'bcc' => 'bccAddresses'] as $key => $property) {
            if (true === array_key_exists($key, $patch)) {
                $message->{$property} = $this->addresses($patch[$key], $key);
            }
        }

        $this->applyReplyContext($message, $patch);

        // Body only when this patch carried one. `body()` reads bodyValues
        // through the part ids in textBody/htmlBody, so naming either without
        // the other's values is what an editor sends.
        if (true === array_key_exists('bodyValues', $patch)) {
            $message->bodyHtml = $this->body($patch);
            $this->bodySanitizer->sanitize($message);
            $message->bodyText = $this->plainText($message->bodyHtml);
        }

        if (null !== $attachments) {
            $this->writeAttachments($message, $attachments);
        }

        $this->entityManager->flush();
    }

    /**
     * The shared draft save, minus the two things this caller does
     * differently. Both are deliberate, and neither is a difference in what a
     * draft is — which is why the rest of it is no longer copied here.
     *
     * Nothing is announced: Email/set records the create itself, because only
     * it knows the creation id the client used and has to answer with it. A
     * second announcement from down here would log a second row for the same
     * message.
     *
     * bodyText is derived by plainText() rather than by the persister's
     * quote-aware extractor. The two genuinely disagree — see plainText() — and
     * resolving that changes what goes out in the text/plain part of mail sent
     * from an app, which is not a refactoring.
     */
    private function persistDraft(Message $message, Account $account): void
    {
        $this->drafts->markAsDraft($message, $account);

        $message->bodyText = $this->plainText($message->bodyHtml);

        $this->drafts->storeAndThread($message, $account);
    }

    /**
     * Turns uploaded blobs into draft attachments.
     *
     * Nothing more than create's entry into the same machinery the update path
     * uses: a create is the whole-value case with no existing parts to keep or
     * to drop, so the two paths deliberately share one implementation rather
     * than being two versions of blob resolution that can drift.
     *
     * @param array<string,mixed> $create
     */
    private function applyAttachments(Message $message, Account $account, array $create): void
    {
        if (false === array_key_exists('attachments', $create)) {
            return;
        }

        $this->writeAttachments($message, $this->planAttachments($message, $account, $create['attachments']));
        $this->entityManager->flush();
    }

    /**
     * Works out what the draft's attachments have to become, changing nothing.
     *
     * `attachments` is a whole value, not a patch — RFC 8620 §5.3 spells a
     * patch as `attachments/0` and this key is the plain property — so the
     * array carries the complete set the draft should end up with, and a part
     * the client left out is one it removed. That is also what the web
     * composer's attachment strip means when it re-renders.
     *
     * A part already on this draft, named by the `p-` blobId Email/get handed
     * out, is kept exactly where it is. Re-uploading a file the server already
     * holds in order to change the subject would be absurd, and the stored copy
     * is what the send path reads.
     *
     * Everything else is resolved through BlobResolver, which filters by
     * account, so a blobId belonging to another account — or another user —
     * resolves to null and is refused rather than attached. The bytes are
     * *copied* into attachment storage rather than referenced in place: an
     * UploadedBlob is scratch space that PruneBlobsCommand reclaims on a timer,
     * so a draft pointing at one would lose its files days later, with nothing
     * to say why.
     *
     * The whole plan is built before the caller writes any of it, so a set
     * naming one bad blobId leaves the draft's existing files alone.
     *
     * @return array{
     *     keep: array<int,array{part:MessagePart,name:?string,type:?string}>,
     *     add: list<array{name:string,type:string,content:string}>,
     * }
     */
    private function planAttachments(Message $message, Account $account, mixed $attachments): array
    {
        if (false === is_array($attachments)) {
            throw new MethodException('invalidProperties', '"attachments" must be an array of EmailBodyPart objects.');
        }

        $onDraft = [];

        foreach ($message->messageParts as $part) {
            if (null !== $part->id) {
                $onDraft[$part->id] = $part;
            }
        }

        $keep = [];
        $add  = [];

        foreach ($attachments as $index => $attachment) {
            if (false === is_array($attachment)) {
                throw new MethodException('invalidProperties', sprintf('attachments[%s] must be an object.', (string) $index));
            }

            $rawId = $attachment['blobId'] ?? null;

            if (false === is_string($rawId) || '' === $rawId) {
                throw new MethodException('invalidProperties', sprintf('attachments[%s].blobId must be a non-empty string.', (string) $index));
            }

            $blobId = BlobId::parse($rawId);

            if (null !== $blobId && true === $blobId->isPart() && true === array_key_exists($blobId->id, $onDraft)) {
                $keep[$blobId->id] = [
                    'part' => $onDraft[$blobId->id],
                    // Only when the client actually said so. An omitted name is
                    // "leave it alone", not "call it attachment-3" — a client
                    // re-listing a part it is not editing sends the blobId and
                    // little else.
                    'name' => $this->declaredName($attachment),
                    'type' => $this->declaredType($attachment),
                ];

                continue;
            }

            // A `p-` blob belonging to a *different* message of this account
            // lands here rather than above, and is copied: that is how a client
            // forwards an attachment into a draft.
            $add[] = [
                'name'    => $this->declaredName($attachment) ?? sprintf('attachment-%s', (string) $index),
                'type'    => $this->declaredType($attachment) ?? 'application/octet-stream',
                'content' => $this->blobContent($account, $blobId, $index),
            ];
        }

        return ['keep' => $keep, 'add' => $add];
    }

    /**
     * Writes a plan out: drop what was not kept, store what is new.
     *
     * Every message_part row in this schema is an attachment — bodies live in
     * columns on the message, and every writer in the app (sync, Gmail, Graph,
     * the composer, this class) sets a filename and a disposition. So "the
     * parts this draft has" and "the parts Email/get published as attachments"
     * are the same set, and a part missing from the plan really was one the
     * client asked to remove.
     *
     * @param array{
     *     keep: array<int,array{part:MessagePart,name:?string,type:?string}>,
     *     add: list<array{name:string,type:string,content:string}>,
     * } $plan
     */
    private function writeAttachments(Message $message, array $plan): void
    {
        foreach ($message->messageParts->toArray() as $part) {
            if (null !== $part->id && true === array_key_exists($part->id, $plan['keep'])) {
                continue;
            }

            // Bytes as well as row, the way the composer's remove button does
            // it. AttachmentStorageHelper::delete ignores anything that is not
            // one of our own relative paths, so a gmail:// part loses its row
            // and its provider copy is left alone.
            $this->attachmentStorage->delete($part->storagePath);
            $message->removeMessagePart($part);
            $this->entityManager->remove($part);
        }

        foreach ($plan['keep'] as $kept) {
            // A rename is the one edit a client can make to a part that is
            // already stored, and it reaches the wire: filename is what the
            // download endpoint offers and what MessageSendService writes into
            // the MIME part. Dropping it silently would be the bug this method
            // exists to fix, one field smaller.
            if (null !== $kept['name']) {
                $kept['part']->filename = $kept['name'];
            }

            if (null !== $kept['type']) {
                $kept['part']->contentType = $kept['type'];
            }
        }

        foreach ($plan['add'] as $entry) {
            $this->storeAttachment($message, $entry['name'], $entry['type'], $entry['content']);
        }

        // Derived rather than assigned, and through the composer's own rule:
        // hardcoding it has already wiped the flag off a draft that had files.
        $this->draftAttachments->syncFlag($message);
    }

    private function storeAttachment(Message $message, string $filename, string $contentType, string $content): void
    {
        $storagePath = $this->attachmentStorage->store(
            (int) $message->account->id,
            (int) ($message->mailbox->id ?? 0),
            (int) $message->id,
            $filename,
            $content,
        );

        $part = new MessagePart();
        $part->message = $message;
        // The client's declared type, as the spec requires it be echoed —
        // but the download endpoint still refuses to render anything but
        // images inline, so a lie here buys nothing.
        $part->contentType = $contentType;
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->size        = strlen($content);
        $part->storagePath = $storagePath;
        $part->isInline    = false;

        $message->addMessagePart($part);
        $this->entityManager->persist($part);
    }

    private function blobContent(Account $account, ?BlobId $blobId, int|string $index): string
    {
        $blob = null !== $blobId ? $this->blobResolver->resolve($account, $blobId) : null;

        if (null === $blob) {
            // Deliberately the same answer for "malformed", "expired", "never
            // existed" and "belongs to someone else": distinguishing them would
            // tell a caller which blob ids are real.
            throw new MethodException('invalidProperties', sprintf('attachments[%s].blobId cannot be resolved.', (string) $index));
        }

        // A resolved blob carries either bytes or a path, depending on
        // which of the four blob kinds it came from.
        $content = $blob->content ?? (null !== $blob->path ? (string) file_get_contents($blob->path) : '');

        if ('' === $content) {
            throw new MethodException('invalidProperties', sprintf('attachments[%s].blobId resolved to no content.', (string) $index));
        }

        return $content;
    }

    /**
     * The filename the client asked for, or null when it named none.
     *
     * @param array<string,mixed> $attachment
     */
    private function declaredName(array $attachment): ?string
    {
        $name = $attachment['name'] ?? null;

        if (false === is_string($name) || '' === trim($name)) {
            return null;
        }

        // basename() only: a name is a display label, and a client that sends
        // "../../etc/passwd" must not be able to steer where it is written.
        return basename(trim($name));
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private function declaredType(array $attachment): ?string
    {
        $type = $attachment['type'] ?? null;

        return is_string($type) && '' !== trim($type) ? trim($type) : null;
    }

    /**
     * @param array<string,mixed> $create
     */
    private function applyReplyContext(Message $message, array $create): void
    {
        $inReplyTo = $create['inReplyTo'] ?? null;

        if (true === is_array($inReplyTo) && count($inReplyTo) > 0) {
            $message->inReplyTo = array_values(array_map('strval', $inReplyTo));
        }

        $references = $create['references'] ?? null;

        if (true === is_array($references) && count($references) > 0) {
            $message->references = array_values(array_map('strval', $references));
        }
    }

    /**
     * JMAP hands the body as bodyValues keyed by the partIds named in
     * htmlBody/textBody. HTML wins when both are supplied, matching the
     * composer, which is HTML-first.
     *
     * @param array<string,mixed> $create
     */
    private function body(array $create): ?string
    {
        $bodyValues = $create['bodyValues'] ?? null;

        if (false === is_array($bodyValues) || 0 === count($bodyValues)) {
            return null;
        }

        $html = $this->valueForParts($bodyValues, $create['htmlBody'] ?? null);

        if (null !== $html) {
            return $html;
        }

        $text = $this->valueForParts($bodyValues, $create['textBody'] ?? null);

        if (null === $text) {
            return null;
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param array<string,mixed> $bodyValues
     */
    private function valueForParts(array $bodyValues, mixed $parts): ?string
    {
        if (false === is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            if (false === is_array($part)) {
                continue;
            }

            $partId = $part['partId'] ?? null;

            if (false === is_string($partId)) {
                continue;
            }

            $value = $bodyValues[$partId]['value'] ?? null;

            if (true === is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The flattened body a client that asked for textBody gets back, and the
     * text/plain part of the mail when it is sent.
     *
     * Not DraftPersister::plainTextBody(), which keeps only the user's own
     * writing and drops the quoted original underneath it. The web composer
     * marks that boundary itself (`data-quoted`) and can rely on finding it;
     * an app's editor marks nothing, so the same cut would land on whatever
     * blockquote the mail happened to contain.
     *
     * The two answers therefore differ for any client that replies with a
     * quote, and unifying them would change what goes out on the wire. It
     * wants deciding rather than merging.
     */
    private function plainText(?string $html): ?string
    {
        if (null === $html || '' === $html) {
            return null;
        }

        $withBreaks = (string) preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * plMail stores addresses as {name, address}; JMAP sends {name, email}.
     *
     * @return list<array{name:?string,address:string}>|null
     */
    private function addresses(mixed $value, string $property): ?array
    {
        if (null === $value) {
            return null;
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be an array of EmailAddress objects.', $property));
        }

        $addresses = [];

        foreach ($value as $entry) {
            if (false === is_array($entry)) {
                throw new MethodException('invalidProperties', sprintf('"%s" must contain EmailAddress objects.', $property));
            }

            $email = $entry['email'] ?? null;

            if (false === is_string($email) || '' === $email) {
                throw new MethodException('invalidProperties', sprintf('Each "%s" entry needs an "email".', $property));
            }

            $name = $entry['name'] ?? null;

            $addresses[] = [
                'name' => is_string($name) && '' !== $name ? $name : null,
                'address' => $email,
            ];
        }

        if (0 === count($addresses)) {
            return null;
        }

        return $addresses;
    }

    private function stringOrNull(mixed $value, string $property): ?string
    {
        if (null === $value) {
            return null;
        }

        if (false === is_string($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be a string.', $property));
        }

        return $value;
    }
}
