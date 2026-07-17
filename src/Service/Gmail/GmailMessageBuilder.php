<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Entity\MessagePart;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Converts a Gmail API message resource (format=full) into a Message entity.
 *
 * The Gmail payload looks like:
 * {
 *   "id": "…",
 *   "threadId": "…",
 *   "labelIds": ["INBOX", "UNREAD"],
 *   "payload": {
 *     "headers": [{"name": "From", "value": "…"}, …],
 *     "body": {"data": "<base64url>"},
 *     "parts": [ … ]
 *   },
 *   "internalDate": "1234567890000"   ← ms since epoch
 * }
 */
final class GmailMessageBuilder
{
    public function __construct(
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly EntityManagerInterface  $em,
    ) {}

    /**
     * @param array<string,mixed> $payload  Decoded JSON from messages.get (format=full)
     */
    public function build(array $payload, Mailbox $mailbox): Message
    {
        $message = new Message();
        $message->setMailbox($mailbox);

        $gmailId = $payload['id'] ?? '';
        $message->setGmailId($gmailId);

        // ── Headers ───────────────────────────────────────────────────────────
        $headers = $this->indexHeaders($payload['payload']['headers'] ?? []);

        $rfcMessageId = $headers['message-id'] ?? '';
        $message->setMessageId($rfcMessageId !== '' ? $rfcMessageId : $gmailId);
        $message->setSubject($this->decodeMimeHeader($headers['subject'] ?? ''));

        // From
        [$fromName, $fromAddress] = $this->parseAddress($headers['from'] ?? '');
        $message->setFromAddress($fromAddress);
        $message->setFromName($fromName);

        // Recipients
        $message->setToAddresses($this->parseAddressList($headers['to'] ?? ''));
        $message->setCcAddresses($this->parseAddressList($headers['cc'] ?? ''));
        $message->setBccAddresses($this->parseAddressList($headers['bcc'] ?? ''));

        // Threading headers
        $inReplyToRaw = trim($headers['in-reply-to'] ?? '');
        $referencesRaw = trim($headers['references'] ?? '');

        $message->setInReplyTo(
            $inReplyToRaw !== '' ? preg_split('/\s+/', $inReplyToRaw) : []
        );
        $message->setReferences(
            $referencesRaw !== '' ? preg_split('/\s+/', $referencesRaw) : []
        );

        // ── Date ──────────────────────────────────────────────────────────────
        $internalDateMs = (int) ($payload['internalDate'] ?? 0);
        $receivedAt = $internalDateMs > 0
            ? (new DateTimeImmutable())->setTimestamp((int) ($internalDateMs / 1000))
            : new DateTimeImmutable();

        $message->setReceivedAt($receivedAt);
        $message->setSentAt($receivedAt);

        // ── Flags / labels ────────────────────────────────────────────────────
        $labelIds = $payload['labelIds'] ?? [];
        $flags    = [];

        if (false === in_array('UNREAD', $labelIds, true)) {
            $flags[] = '\\Seen';
            $message->setSeenAt(new DateTimeImmutable());
        }

        if (true === in_array('STARRED', $labelIds, true)) {
            $flags[] = '\\Flagged';
            $message->setStarredAt(new DateTimeImmutable());
        }

        if (true === in_array('DRAFT', $labelIds, true)) {
            $flags[] = '\\Draft';
        }

        $message->setFlags($flags);

        // ── Body + attachments ────────────────────────────────────────────────
        $accountId = $mailbox->getAccount()->getId();
        // We use the gmail message id as UID stand-in for attachment storage paths
        $fakeUid   = abs(crc32($gmailId));

        [$bodyText, $bodyHtml, $hasAttachments] = $this->extractBody(
            $payload['payload'] ?? [],
            $message,
            $mailbox,
            $accountId,
            $fakeUid,
        );

        $message->setBodyText($bodyText);
        $message->setBodyHtml($bodyHtml);
        $message->setHasAttachments($hasAttachments);
        $message->setSyncedAt(new DateTimeImmutable());

        // Store the Gmail message ID so we can dedup on subsequent syncs.
        // We abuse imapUid = null for Gmail (it has no IMAP UID in our flow)
        // and use messageId for dedup instead.

        return $message;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param list<array{name: string, value: string}> $headers
     * @return array<string,string>  lower-cased name => value
     */
    private function indexHeaders(array $headers): array
    {
        $index = [];
        foreach ($headers as $h) {
            $index[strtolower((string) ($h['name'] ?? ''))] = (string) ($h['value'] ?? '');
        }
        return $index;
    }

    /**
     * Parse a single RFC-5322 address like "Name <email>" or bare "email".
     *
     * @return array{string, string}  [name, address]
     */
    private function parseAddress(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/^(.+?)\s*<([^>]+)>$/', $raw, $m)) {
            return [trim($m[1], ' "\''), strtolower(trim($m[2]))];
        }

        return ['', strtolower($raw)];
    }

    /**
     * Parse a comma-separated list of RFC-5322 addresses.
     *
     * @return list<array{name: string, address: string}>
     */
    private function parseAddressList(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        $result = [];
        // Split on commas that are not inside angle brackets
        $parts = preg_split('/,(?![^<]*>)/', $raw) ?: [];

        foreach ($parts as $part) {
            [$name, $address] = $this->parseAddress($part);
            if ('' !== $address) {
                $result[] = ['name' => $name, 'address' => $address];
            }
        }

        return $result;
    }

    /**
     * Walk the MIME tree and extract text/html body parts and attachments.
     *
     * @param array<string,mixed> $part
     * @return array{string, string, bool}  [bodyText, bodyHtml, hasAttachments]
     */
    private function extractBody(
        array   $part,
        Message $message,
        Mailbox $mailbox,
        int     $accountId,
        int     $fakeUid,
    ): array {
        $bodyText       = '';
        $bodyHtml       = '';
        $hasAttachments = false;

        $mimeType   = strtolower((string) ($part['mimeType'] ?? ''));
        $disposition = strtolower((string) ($part['headers']['content-disposition'] ?? ''));

        // Leaf node with body data
        if (true === isset($part['body']['data'])) {
            $decoded = base64_decode(strtr((string) $part['body']['data'], '-_', '+/'));

            if ('text/plain' === $mimeType) {
                $bodyText = $decoded;
            } elseif ('text/html' === $mimeType) {
                $bodyHtml = $decoded;
            }
        }

        // Attachment or inline part backed by a separate attachmentId
        if (true === isset($part['body']['attachmentId'])) {
            $partHeaders  = $this->indexHeaders($part['headers'] ?? []);
            $filename     = (string) ($part['filename'] ?? '');
            $hasContentId = '' !== trim((string) ($partHeaders['content-id'] ?? ''), '<> ');

            if ('' !== $filename || true === $hasContentId) {
                $isInline = $this->persistAttachmentStub($part, $message, $mailbox, $accountId, $fakeUid);

                // Only real (non-inline) attachments flip the paperclip.
                if (false === $isInline) {
                    $hasAttachments = true;
                }
            }
        }

        // Recurse into sub-parts (multipart/*)
        foreach ($part['parts'] ?? [] as $subPart) {
            [$t, $h, $a] = $this->extractBody($subPart, $message, $mailbox, $accountId, $fakeUid);
            if ('' === $bodyText) { $bodyText = $t; }
            if ('' === $bodyHtml) { $bodyHtml = $h; }
            if (true === $a)      { $hasAttachments = true; }
        }

        return [$bodyText, $bodyHtml, $hasAttachments];
    }

    /**
     * Persist a MessagePart stub for an attachment. Bytes are fetched lazily by
     * AttachmentResolver on first access.
     *
     * @param array<string,mixed> $part
     * @return bool  true if the part is inline (referenced from the body)
     */
    private function persistAttachmentStub(
        array   $part,
        Message $message,
        Mailbox $mailbox,
        int     $accountId,
        int     $fakeUid,
    ): bool {
        $partHeaders  = $this->indexHeaders($part['headers'] ?? []);
        $filename     = (string) ($part['filename'] ?? 'attachment');
        $contentType  = (string) ($part['mimeType'] ?? 'application/octet-stream');
        $attachmentId = (string) ($part['body']['attachmentId'] ?? '');
        $size         = (int) ($part['body']['size'] ?? 0);

        $contentId   = trim((string) ($partHeaders['content-id'] ?? ''), '<> ');
        $disposition = strtolower((string) ($partHeaders['content-disposition'] ?? ''));
        $isInline    = true === str_contains($disposition, 'inline') || '' !== $contentId;

        $mp = new MessagePart();
        $mp->setMessage($message);
        $mp->setContentType($contentType);
        $mp->setFilename($filename);
        $mp->setContentId('' !== $contentId ? $contentId : null);
        $mp->setDisposition($isInline ? 'inline' : 'attachment');
        $mp->setSize($size);
        $mp->setStoragePath('gmail://' . $attachmentId);
        $mp->setIsInline($isInline);

        $this->em->persist($mp);

        return $isInline;
    }

    private function decodeMimeHeader(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return (false === $decoded) ? $value : $decoded;
    }
}
