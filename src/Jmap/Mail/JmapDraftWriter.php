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
use App\Service\Mail\DraftPersister;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a JMAP Email/set "create" object into a persisted plMail draft.
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
     * so a draft edited from three devices stays one draft.
     *
     * @param array<string,mixed> $patch already filtered to draft properties
     */
    public function update(Message $message, array $patch): void
    {
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
     * RFC 8621 has a client upload bytes to /jmap/upload and then name the
     * returned blobId in Email.attachments. The bytes are *copied* into
     * attachment storage rather than referenced in place: an UploadedBlob is
     * scratch space that PruneBlobsCommand reclaims on a timer, so a draft
     * pointing at one would lose its files days later, with nothing to say
     * why.
     *
     * Resolution goes through BlobResolver, which filters by account — so a
     * blobId belonging to another account, or another user, resolves to null
     * and is refused rather than attached.
     *
     * @param array<string,mixed> $create
     */
    private function applyAttachments(Message $message, Account $account, array $create): void
    {
        $attachments = $create['attachments'] ?? null;

        if (false === is_array($attachments) || 0 === count($attachments)) {
            return;
        }

        $stored = 0;

        foreach ($attachments as $index => $attachment) {
            if (false === is_array($attachment)) {
                throw new MethodException('invalidProperties', sprintf('attachments[%s] must be an object.', (string) $index));
            }

            $rawId = $attachment['blobId'] ?? null;

            if (false === is_string($rawId) || '' === $rawId) {
                throw new MethodException('invalidProperties', sprintf('attachments[%s].blobId must be a non-empty string.', (string) $index));
            }

            $blobId = BlobId::parse($rawId);
            $blob = null !== $blobId ? $this->blobResolver->resolve($account, $blobId) : null;

            if (null === $blob) {
                // Deliberately the same answer for "expired", "never existed"
                // and "belongs to someone else": distinguishing them would tell
                // a caller which blob ids are real.
                throw new MethodException('invalidProperties', sprintf('attachments[%s].blobId cannot be resolved.', (string) $index));
            }

            // A resolved blob carries either bytes or a path, depending on
            // which of the four blob kinds it came from.
            $content = $blob->content ?? (null !== $blob->path ? (string) file_get_contents($blob->path) : '');

            if ('' === $content) {
                throw new MethodException('invalidProperties', sprintf('attachments[%s].blobId resolved to no content.', (string) $index));
            }

            $filename = $this->attachmentName($attachment, $index);

            $storagePath = $this->attachmentStorage->store(
                (int) $account->id,
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
            $part->contentType = $this->attachmentType($attachment);
            $part->filename    = $filename;
            $part->disposition = 'attachment';
            $part->size        = strlen($content);
            $part->storagePath = $storagePath;
            $part->isInline    = false;

            $message->addMessagePart($part);
            $this->entityManager->persist($part);

            ++$stored;
        }

        $message->hasAttachments = $stored > 0;
        $this->entityManager->flush();
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private function attachmentName(array $attachment, int|string $index): string
    {
        $name = $attachment['name'] ?? null;

        if (false === is_string($name) || '' === trim($name)) {
            return sprintf('attachment-%s', (string) $index);
        }

        // basename() only: a name is a display label, and a client that sends
        // "../../etc/passwd" must not be able to steer where it is written.
        return basename(trim($name));
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private function attachmentType(array $attachment): string
    {
        $type = $attachment['type'] ?? null;

        return is_string($type) && '' !== trim($type)
            ? trim($type)
            : 'application/octet-stream';
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
