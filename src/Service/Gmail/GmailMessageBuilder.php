<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MimeHeaderHelper;
use App\Entity\Account;
use App\Entity\Label;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Service\Label\LabelResolver;
use App\Service\Mail\InlineAttachmentDetector;
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
        private readonly InlineAttachmentDetector $inlineDetector,
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
        $message = new Message()
            ->setAccount($account);

        $gmailId = (string)($payload['id'] ?? '');
        $labelIds = array_values(array_map('strval', $payload['labelIds'] ?? []));

        $message->setGmailId($gmailId);
        $message->setGmailLabelIds($labelIds);

        // Gmail already grouped this conversation for the user; carrying its
        // threadId over means our threads match what they see in Gmail itself.
        $threadId = trim((string) ($payload['threadId'] ?? ''));
        $message->setProviderThreadKey('' !== $threadId ? $threadId : null);

        $this->applyTranslatedLabels($message, $labelIds, $account, $carrierAccount ?? $account);

        // ── Headers ───────────────────────────────────────────────────────────
        $headers = $this->indexHeaders($payload['payload']['headers'] ?? []);

        $rfcMessageId = MessageIdHelper::normalise($headers['message-id'] ?? '');
        $message->setMessageId('' !== $rfcMessageId ? $rfcMessageId : $gmailId);
        $message->setSubject($this->decodeMimeHeader($headers['subject'] ?? ''));

        [$fromName, $fromAddress] = $this->parseAddress($headers['from'] ?? '');
        $message->setFromAddress($fromAddress);
        $message->setFromName($fromName);

        $message->setToAddresses($this->parseAddressList($headers['to'] ?? ''));
        $message->setCcAddresses($this->parseAddressList($headers['cc'] ?? ''));
        $message->setBccAddresses($this->parseAddressList($headers['bcc'] ?? ''));

        $message->setInReplyTo(MessageIdHelper::normaliseList($headers['in-reply-to'] ?? null));
        $message->setReferences(MessageIdHelper::normaliseList($headers['references'] ?? null));

        // ── Date ──────────────────────────────────────────────────────────────
        $internalDateMs = (int)($payload['internalDate'] ?? 0);
        $receivedAt = $internalDateMs > 0
            ? new DateTimeImmutable()->setTimestamp((int)($internalDateMs / 1000))
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

        // ── Headers ──────────────────────────────────────────────────────────

        $rawHeaders = [];

        foreach ($payload['payload']['headers'] ?? [] as $header) {
            $name = (string) ($header['name'] ?? '');

            if ('' === $name) {
                continue;
            }

            if (true === isset($rawHeaders[$name])) {
                $rawHeaders[$name] = array_merge((array) $rawHeaders[$name], [(string) ($header['value'] ?? '')]);
                continue;
            }

            $rawHeaders[$name] = (string) ($header['value'] ?? '');
        }

        $message->setHeaders($rawHeaders);

        // ── Body + attachments ────────────────────────────────────────────────

        // Attachment parts are collected first and persisted afterwards: the
        // inline/attachment decision needs the HTML body, which the same walk
        // is still assembling.
        [$bodyText, $bodyHtml, $attachmentParts] = $this->extractBody(
            $payload['payload'] ?? [],
        );

        $message->setBodyText($bodyText);
        $message->setBodyHtml($bodyHtml);
        $message->setHasAttachments(
            $this->persistAttachmentStubs($attachmentParts, $message, $bodyHtml)
        );
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
     * @return array{string, string, list<array<string,mixed>>}  [bodyText, bodyHtml, attachmentParts]
     */
    private function extractBody(array $part): array
    {
        $bodyText = '';
        $bodyHtml = '';
        $attachmentParts = [];

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
            $hasContentId = '' !== trim(($partHeaders['content-id'] ?? ''), '<> ');

            if ('' !== $filename || true === $hasContentId) {
                $attachmentParts[] = $part;
            }
        }

        foreach ($part['parts'] ?? [] as $subPart) {
            [$t, $h, $a] = $this->extractBody($subPart);
            if ('' === $bodyText) {
                $bodyText = $t;
            }
            if ('' === $bodyHtml) {
                $bodyHtml = $h;
            }

            $attachmentParts = array_merge($attachmentParts, $a);
        }

        return [$bodyText, $bodyHtml, $attachmentParts];
    }

    /**
     * Persist MessagePart stubs for the collected attachment parts. Bytes are
     * fetched lazily by AttachmentResolver on first access.
     *
     * @param list<array<string,mixed>> $parts
     * @return bool  true if at least one part is a real (non-inline) attachment
     */
    private function persistAttachmentStubs(array $parts, Message $message, string $bodyHtml): bool
    {
        $hasAttachments = false;

        foreach ($parts as $part) {
            $partHeaders = $this->indexHeaders($part['headers'] ?? []);
            $filename = (string)($part['filename'] ?? 'attachment');
            $contentType = (string)($part['mimeType'] ?? 'application/octet-stream');
            $attachmentId = (string)($part['body']['attachmentId'] ?? '');
            $size = (int)($part['body']['size'] ?? 0);

            $contentId = $this->inlineDetector->normalizeContentId($partHeaders['content-id'] ?? null);
            $isInline = $this->inlineDetector->isInline(
                $partHeaders['content-disposition'] ?? null,
                $contentId,
                $bodyHtml,
            );

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

            if (false === $isInline) {
                $hasAttachments = true;
            }
        }

        return $hasAttachments;
    }

    private function decodeMimeHeader(string $value): string
    {
        return MimeHeaderHelper::decode($value);
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

    /**
     * Resolve Gmail labelIds against the carrier account and attach the
     * translated labels for the target account. Shared by the import path
     * (new messages) and the enrichment path (existing IMAP rows gaining
     * their Gmail labels after dedup).
     *
     * @param list<string> $labelIds
     */
    public function applyTranslatedLabels(Message $message, array $labelIds, Account $target, Account $carrier): void
    {
        foreach ($this->labelResolver->resolve($labelIds, $carrier) as $label) {
            $message->addLabel($this->translateLabel($label, $target));
        }
    }
}
