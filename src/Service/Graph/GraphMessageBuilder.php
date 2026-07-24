<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Account;
use App\Entity\Message;
use App\Entity\MessagePart;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Converts a Microsoft Graph message resource into a Message entity.
 *
 * Considerably simpler than GmailMessageBuilder: Graph hands back parsed
 * headers and a single decoded body rather than a base64url MIME part tree,
 * so there is no walking, no base64url decoding, and no MIME header decoding.
 *
 * The Graph payload looks like:
 * {
 *   "id": "AAMkAD…",
 *   "internetMessageId": "<abc@example.com>",
 *   "conversationId": "AAQkAD…",
 *   "subject": "…",
 *   "from": {"emailAddress": {"name": "…", "address": "…"}},
 *   "toRecipients": [{"emailAddress": {…}}],
 *   "receivedDateTime": "2026-07-24T09:12:33Z",
 *   "isRead": true,
 *   "isDraft": false,
 *   "flag": {"flagStatus": "flagged"},
 *   "body": {"contentType": "html", "content": "…"},
 *   "hasAttachments": true,
 *   "parentFolderId": "AAMkAD…",
 *   "internetMessageHeaders": [{"name": "Received", "value": "…"}],
 *   "categories": ["Red category"]
 * }
 *
 * Note on internetMessageHeaders: Graph returns it only for messages that were
 * received (not for drafts), and truncates it on very large header sets. It is
 * used for In-Reply-To/References, which fall back to conversationId-derived
 * threading when absent.
 */
final class GraphMessageBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GraphFolderResolver    $folderResolver,
    ) {}

    /**
     * @param array<string,mixed>                $payload      messages.get resource
     * @param list<array<string,mixed>>          $attachments  from batchListAttachments()
     */
    public function build(array $payload, Account $account, array $attachments = []): Message
    {
        $message = new Message()
            ->setAccount($account);

        $graphId = (string) ($payload['id'] ?? '');
        $message->setGraphId($graphId);

        // ── Identity ──────────────────────────────────────────────────────────
        // The RFC Message-ID is the dedup key, not the Graph id: Graph ids are
        // locators and can rotate when a message moves. Fall back to the Graph
        // id only when the mailbox genuinely has no Message-ID (drafts).
        $rfcMessageId = trim((string) ($payload['internetMessageId'] ?? ''));
        $message->setMessageId('' !== $rfcMessageId ? $rfcMessageId : $graphId);

        $message->setSubject((string) ($payload['subject'] ?? ''));

        // ── Addresses ─────────────────────────────────────────────────────────
        [$fromName, $fromAddress] = $this->parseRecipient($payload['from'] ?? $payload['sender'] ?? null);
        $message->setFromAddress($fromAddress);
        $message->setFromName($fromName);

        $message->setToAddresses($this->parseRecipientList($payload['toRecipients'] ?? []));
        $message->setCcAddresses($this->parseRecipientList($payload['ccRecipients'] ?? []));
        $message->setBccAddresses($this->parseRecipientList($payload['bccRecipients'] ?? []));

        // ── Headers ───────────────────────────────────────────────────────────
        $rawHeaders = [];
        $indexed    = [];

        foreach ($payload['internetMessageHeaders'] ?? [] as $header) {
            $name  = (string) ($header['name'] ?? '');
            $value = (string) ($header['value'] ?? '');

            if ('' === $name) {
                continue;
            }

            $indexed[strtolower($name)] = $value;

            if (true === array_key_exists($name, $rawHeaders)) {
                $rawHeaders[$name] = array_merge((array) $rawHeaders[$name], [$value]);
                continue;
            }

            $rawHeaders[$name] = $value;
        }

        $message->setHeaders($rawHeaders);

        $inReplyToRaw  = trim($indexed['in-reply-to'] ?? '');
        $referencesRaw = trim($indexed['references'] ?? '');

        $message->setInReplyTo(
            '' !== $inReplyToRaw ? preg_split('/\s+/', $inReplyToRaw) : []
        );
        $message->setReferences(
            '' !== $referencesRaw ? preg_split('/\s+/', $referencesRaw) : []
        );

        // ── Dates ─────────────────────────────────────────────────────────────
        $receivedAt = $this->parseDate($payload['receivedDateTime'] ?? null) ?? new DateTimeImmutable();
        $sentAt     = $this->parseDate($payload['sentDateTime'] ?? null);

        $message->setReceivedAt($receivedAt);
        $message->setSentAt($sentAt ?? $receivedAt);

        // ── Flags ─────────────────────────────────────────────────────────────
        $flags   = [];
        $isRead  = true === ($payload['isRead'] ?? false);
        $isDraft = true === ($payload['isDraft'] ?? false);
        $flagged = 'notFlagged' !== (string) ($payload['flag']['flagStatus'] ?? 'notFlagged');

        if (true === $isRead) {
            $flags[] = '\\Seen';
            $message->setSeenAt($receivedAt);
        }

        if (true === $flagged) {
            $flags[] = '\\Flagged';
            $message->setStarredAt($receivedAt);
        }

        if (true === $isDraft) {
            $flags[] = '\\Draft';
        }

        $message->setFlags($flags);

        // ── Labels ────────────────────────────────────────────────────────────
        // Exchange messages live in exactly one folder, so there is exactly one
        // location label. Categories are the many-to-many axis on top.
        $folderLabel = $this->folderResolver->resolveFolder(
            (string) ($payload['parentFolderId'] ?? ''),
            $account,
        );

        if (null !== $folderLabel) {
            $message->addLabel($folderLabel);
        }

        $categories = array_values(array_map('strval', $payload['categories'] ?? []));

        foreach ($this->folderResolver->resolveCategories($categories, $account) as $categoryLabel) {
            $message->addLabel($categoryLabel);
        }

        // ── Body ──────────────────────────────────────────────────────────────
        $bodyContentType = strtolower((string) ($payload['body']['contentType'] ?? 'text'));
        $bodyContent     = (string) ($payload['body']['content'] ?? '');

        if ('html' === $bodyContentType) {
            $message->setBodyHtml($bodyContent);
            $message->setBodyText((string) ($payload['bodyPreview'] ?? ''));
        } else {
            $message->setBodyText($bodyContent);
            $message->setBodyHtml('');
        }

        // ── Attachments ───────────────────────────────────────────────────────
        $hasAttachments = $this->applyAttachments($message, $attachments);

        $message->setHasAttachments($hasAttachments);
        $message->setSyncedAt(new DateTimeImmutable());

        return $message;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Persist MessagePart stubs. Bytes are not fetched here — the storagePath
     * is a `msgraph://` URI resolved lazily on first access by
     * AttachmentResolver, mirroring the `gmail://` scheme.
     *
     * itemAttachment (an embedded message or event) and referenceAttachment
     * (a OneDrive link) are NOT files and are skipped: fetching /$value on
     * them errors, and neither has bytes to store.
     *
     * @param list<array<string,mixed>> $attachments
     */
    private function applyAttachments(Message $message, array $attachments): bool
    {
        $found = false;

        foreach ($attachments as $attachment) {
            $odataType = (string) ($attachment['@odata.type'] ?? '#microsoft.graph.fileAttachment');

            if (false === str_contains($odataType, 'fileAttachment')) {
                continue;
            }

            $attachmentId = (string) ($attachment['id'] ?? '');

            if ('' === $attachmentId) {
                continue;
            }

            $contentId = trim((string) ($attachment['contentId'] ?? ''), '<> ');
            $isInline  = true === ($attachment['isInline'] ?? false) || '' !== $contentId;

            $part = new MessagePart()
                ->setMessage($message)
                ->setContentType((string) ($attachment['contentType'] ?? 'application/octet-stream'))
                ->setFilename((string) ($attachment['name'] ?? ''))
                ->setContentId('' !== $contentId ? $contentId : null)
                ->setDisposition($isInline ? 'inline' : 'attachment')
                ->setSize((int) ($attachment['size'] ?? 0))
                ->setStoragePath('msgraph://' . $attachmentId)
                ->setIsInline($isInline);

            $this->em->persist($part);

            if (false === $isInline) {
                $found = true;
            }
        }

        return $found;
    }

    /**
     * @return array{string, string}  [name, address]
     */
    private function parseRecipient(mixed $recipient): array
    {
        if (false === is_array($recipient)) {
            return ['', ''];
        }

        $emailAddress = $recipient['emailAddress'] ?? [];

        return [
            trim((string) ($emailAddress['name'] ?? '')),
            strtolower(trim((string) ($emailAddress['address'] ?? ''))),
        ];
    }

    /**
     * @param list<array<string,mixed>> $recipients
     * @return list<array{name: string, address: string}>
     */
    private function parseRecipientList(array $recipients): array
    {
        $result = [];

        foreach ($recipients as $recipient) {
            [$name, $address] = $this->parseRecipient($recipient);

            if ('' !== $address) {
                $result[] = ['name' => $name, 'address' => $address];
            }
        }

        return $result;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (false === is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
