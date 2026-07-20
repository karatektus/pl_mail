<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Account;
use App\Entity\Label;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Converts a Gmail API message resource (format=full) into a Message entity.
 *
 * The Gmail payload looks like:
 * {
 *   "id": "…",
 *   "threadId": "…",
 *   "labelIds": ["INBOX", "UNREAD", "STARRED"],
 *   "payload": {
 *     "headers": [{"name": "From", "value": "…"}, …],
 *     "body": {"data": "<base64url>"},
 *     "parts": [ … ]
 *   },
 *   "internalDate": "1234567890000"   ← ms since epoch
 * }
 *
 * The $fallbackMailbox passed to build() is only used when the label router
 * cannot find a matching local mailbox (e.g. the account has no Sent folder
 * yet). The router is the authoritative source for mailbox assignment.
 */
final class GmailMessageBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GmailLabelResolver     $labelResolver,
        private readonly LabelResolver          $localLabelResolver,
    )
    {
    }

    /**
     * @param array<string,mixed> $payload         Decoded JSON from messages.get (format=full)
     * @param Account             $account         Account the message is ATTRIBUTED to
     * @param Account|null        $carrierAccount  Gmail account whose labelIds these are
     *                                             (differs from $account for Gmailify imports)
     */
    public function build(array $payload, Account $account, ?Account $carrierAccount = null): Message
    {
        $message = new Message();

        $gmailId = (string)($payload['id'] ?? '');
        $labelIds = array_values(array_map('strval', $payload['labelIds'] ?? []));

        $message->setGmailId($gmailId);
        $message->setGmailLabelIds($labelIds);

        // Resolve labelIds against the carrier (the Gmail account that owns
        // them), then translate onto the attributed account when they differ:
        // system labels map by role, custom labels by name chain.
        $resolutionAccount = $carrierAccount ?? $account;

        foreach ($this->labelResolver->resolve($labelIds, $resolutionAccount) as $label) {
            $message->addLabel($this->translateLabel($label, $account));
        }

        // ── Headers ───────────────────────────────────────────────────────────
        $headers = $this->indexHeaders($payload['payload']['headers'] ?? []);

        $rfcMessageId = $headers['message-id'] ?? '';
        $message->setMessageId('' !== $rfcMessageId ? $rfcMessageId : $gmailId);
        $message->setSubject($this->decodeMimeHeader($headers['subject'] ?? ''));

        [$fromName, $fromAddress] = $this->parseAddress($headers['from'] ?? '');
        $message->setFromAddress($fromAddress);
        $message->setFromName($fromName);

        $message->setToAddresses($this->parseAddressList($headers['to'] ?? ''));
        $message->setCcAddresses($this->parseAddressList($headers['cc'] ?? ''));
        $message->setBccAddresses($this->parseAddressList($headers['bcc'] ?? ''));

        $inReplyToRaw = trim($headers['in-reply-to'] ?? '');
        $referencesRaw = trim($headers['references'] ?? '');

        $message->setInReplyTo(
            '' !== $inReplyToRaw ? preg_split('/\s+/', $inReplyToRaw) : []
        );
        $message->setReferences(
            '' !== $referencesRaw ? preg_split('/\s+/', $referencesRaw) : []
        );

        // ── Date ──────────────────────────────────────────────────────────────
        $internalDateMs = (int)($payload['internalDate'] ?? 0);
        $receivedAt = $internalDateMs > 0
            ? (new DateTimeImmutable())->setTimestamp((int)($internalDateMs / 1000))
            : new DateTimeImmutable();

        $message->setReceivedAt($receivedAt);
        $message->setSentAt($receivedAt);

        // ── Flags (derived from label IDs) ────────────────────────────────────
        $flags = [];

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

        [$bodyText, $bodyHtml, $hasAttachments] = $this->extractBody(
            $payload['payload'] ?? [],
            $message,
        );

        $message->setBodyText($bodyText);
        $message->setBodyHtml($bodyHtml);
        $message->setHasAttachments($hasAttachments);
        $message->setSyncedAt(new DateTimeImmutable());

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
            $index[strtolower((string)($h['name'] ?? ''))] = (string)($h['value'] ?? '');
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
    ): array
    {
        $bodyText = '';
        $bodyHtml = '';
        $hasAttachments = false;

        $mimeType = strtolower((string)($part['mimeType'] ?? ''));

        if (true === isset($part['body']['data'])) {
            $decoded = base64_decode(strtr((string)$part['body']['data'], '-_', '+/'));

            if ('text/plain' === $mimeType) {
                $bodyText = $decoded;
            } elseif ('text/html' === $mimeType) {
                $bodyHtml = $decoded;
            }
        }

        if (true === isset($part['body']['attachmentId'])) {
            $partHeaders = $this->indexHeaders($part['headers'] ?? []);
            $filename = (string)($part['filename'] ?? '');
            $hasContentId = '' !== trim((string)($partHeaders['content-id'] ?? ''), '<> ');

            if ('' !== $filename || true === $hasContentId) {
                $isInline = $this->persistAttachmentStub($part, $message);

                if (false === $isInline) {
                    $hasAttachments = true;
                }
            }
        }

        foreach ($part['parts'] ?? [] as $subPart) {
            [$t, $h, $a] = $this->extractBody($subPart, $message);
            if ('' === $bodyText) {
                $bodyText = $t;
            }
            if ('' === $bodyHtml) {
                $bodyHtml = $h;
            }
            if (true === $a) {
                $hasAttachments = true;
            }
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
    ): bool
    {
        $partHeaders = $this->indexHeaders($part['headers'] ?? []);
        $filename = (string)($part['filename'] ?? 'attachment');
        $contentType = (string)($part['mimeType'] ?? 'application/octet-stream');
        $attachmentId = (string)($part['body']['attachmentId'] ?? '');
        $size = (int)($part['body']['size'] ?? 0);

        $contentId = trim($partHeaders['content-id'] ?? '', '<> ');
        $disposition = strtolower($partHeaders['content-disposition'] ?? '');
        $isInline = true === str_contains($disposition, 'inline') || '' !== $contentId;

        $mp = new MessagePart()
            ->setMessage($message)
            ->setContentType($contentType)
            ->setFilename($filename)
            ->setContentId('' !== $contentId ? $contentId : null)
            ->setDisposition($isInline ? 'inline' : 'attachment')
            ->setSize($size)
            ->setStoragePath('gmail://' . $attachmentId)
            ->setIsInline($isInline);

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

    private function translateLabel(Label $label, Account $target): Label
    {
        if ($label->account === $target) {
            return $label;
        }

        if (null !== $label->role) {
            return $this->localLabelResolver->systemLabel($label->role, $target);
        }

        $translated = $this->localLabelResolver->customChain(
            explode('/', (string) $label->fullName),
            $target,
        );

        if (null === $translated) {
            throw new \LogicException(sprintf(
                'Could not translate label "%s" onto account %d',
                (string) $label->fullName,
                (int) $target->getId(),
            ));
        }

        return $translated;
    }
}
