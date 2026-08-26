<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\DTO\Gmail\ExtractedBody;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\CharsetHelper;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MimeHeaderHelper;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Label\LabelResolver;
use App\Service\Mail\HeaderNormalizer;
use App\Service\Mail\InlineAttachmentDetector;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
        private readonly HeaderNormalizer       $headerNormalizer,
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly LoggerInterface        $logger,
        private readonly GmailLabelPolicy       $labelPolicy,
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
        $message->account = $account;

        $gmailId = (string)($payload['id'] ?? '');
        $labelIds = array_values(array_map('strval', $payload['labelIds'] ?? []));

        $message->gmailId = $gmailId;
        $message->gmailLabelIds = $labelIds;

        // Gmail already grouped this conversation for the user; carrying its
        // threadId over means our threads match what they see in Gmail itself.
        $threadId = trim((string) ($payload['threadId'] ?? ''));
        $message->providerThreadKey = '' !== $threadId ? $threadId : null;

        $this->applyTranslatedLabels($message, $labelIds, $account, $carrierAccount ?? $account);

        // ── Headers ───────────────────────────────────────────────────────────
        $headers = $this->indexHeaders($payload['payload']['headers'] ?? []);

        $rfcMessageId = MessageIdHelper::normalise($headers['message-id'] ?? '');
        $message->messageId = '' !== $rfcMessageId ? $rfcMessageId : $gmailId;
        $message->subject = $this->decodeMimeHeader($headers['subject'] ?? '');

        [$fromName, $fromAddress] = $this->parseAddress($headers['from'] ?? '');
        $message->fromAddress = $fromAddress;
        $message->fromName = $fromName;

        $message->toAddresses = $this->parseAddressList($headers['to'] ?? '');
        $message->ccAddresses = $this->parseAddressList($headers['cc'] ?? '');
        $message->bccAddresses = $this->parseAddressList($headers['bcc'] ?? '');

        $message->inReplyTo = MessageIdHelper::normaliseList($headers['in-reply-to'] ?? null);
        $message->references = MessageIdHelper::normaliseList($headers['references'] ?? null);

        // ── Date ──────────────────────────────────────────────────────────────
        $internalDateMs = (int)($payload['internalDate'] ?? 0);
        $receivedAt = $internalDateMs > 0
            ? new DateTimeImmutable()->setTimestamp((int)($internalDateMs / 1000))
            : new DateTimeImmutable();

        $message->receivedAt = $receivedAt;
        $message->sentAt = $receivedAt;

        // ── Flags (derived from label IDs) ────────────────────────────────────
        $flags = [];

        if (false === in_array('UNREAD', $labelIds, true)) {
            $flags[] = '\\Seen';
            $message->seenAt = new DateTimeImmutable();
        }

        if (true === in_array('STARRED', $labelIds, true)) {
            $flags[] = '\\Flagged';
            $message->starredAt = new DateTimeImmutable();
        }

        if (true === in_array('DRAFT', $labelIds, true)) {
            $flags[] = '\\Draft';
        }

        $message->flags = $flags;

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

        $message->headers = $this->headerNormalizer->normalize($rawHeaders);

        // ── Body + attachments ────────────────────────────────────────────────

        // Attachment parts are collected first and persisted afterwards: the
        // inline/attachment decision needs the HTML body, which the same walk
        // is still assembling.
        $body = $this->extractBody($payload['payload'] ?? []);

        $message->bodyText = $body->bodyText;
        $message->bodyHtml = $body->bodyHtml;

        $hasAttachments = $this->persistAttachmentStubs($body->lazyParts, $message, $body->bodyHtml);
        $this->persistInlineParts($body->inlineParts, $message, $account);

        $message->hasAttachments = $hasAttachments;
        $message->syncedAt = new DateTimeImmutable();

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

        if (1 === preg_match('/^(.*)<([^<>]*)>\s*$/', $raw, $m)) {
            return [AddressHelper::name($m[1]), AddressHelper::email($m[2])];
        }

        return ['', AddressHelper::email($raw)];
    }

    /**
     * Parse a comma-separated list of RFC-5322 addresses.
     *
     * @return list<array{name: string, address: string}>
     */
    private function parseAddressList(string $raw): array
    {
        $result = [];

        foreach (AddressHelper::splitList($raw) as $part) {
            [$name, $address] = $this->parseAddress($part);

            if ('' !== $address) {
                $result[] = ['name' => $name, 'address' => $address];
            }
        }

        return $result;
    }

    /**
     * MIME types kept whatever else the part looks like.
     *
     * The gate below exists to stop the text/plain and text/html body parts
     * being persisted as attachments, and it does that by requiring a filename
     * or a Content-ID. A calendar invite has neither: Google Calendar sends
     * `text/calendar; method=REQUEST` inside multipart/alternative, unnamed,
     * with no Content-ID, and — when it is small — no attachmentId either, so
     * it matched no branch at all and no MessagePart row was ever written. On
     * a Gmail account the invite simply did not exist.
     *
     * Kept deliberately narrow: these are types that are neither a body nor a
     * user-facing attachment, and widening it re-opens the problem the gate
     * was put there to solve.
     *
     * @var list<string>
     */
    private const array ALWAYS_KEEP_MIME = ['text/calendar', 'application/ics'];

    /**
     * Walk the MIME tree for the body and the parts worth keeping.
     *
     * @param array<string,mixed> $part
     */
    private function extractBody(array $part): ExtractedBody
    {
        $bodyText    = '';
        $bodyHtml    = '';
        $lazyParts   = [];
        $inlineParts = [];

        $mimeType = strtolower((string)($part['mimeType'] ?? ''));
        $keepAlways = in_array($mimeType, self::ALWAYS_KEEP_MIME, true);

        if (true === isset($part['body']['data'])) {
            $decoded = base64_decode(strtr((string)$part['body']['data'], '-_', '+/'));

            if ('text/plain' === $mimeType) {
                $bodyText = $this->toUtf8($decoded, $part);
            } elseif ('text/html' === $mimeType) {
                $bodyHtml = $this->toUtf8($decoded, $part);
            } elseif (true === $keepAlways && '' !== $decoded) {
                // Bytes are already here, so there is nothing to fetch later —
                // this is the common shape for an invite, which is small.
                $inlineParts[] = ['part' => $part, 'bytes' => $decoded];
            }
        }

        if (true === isset($part['body']['attachmentId'])) {
            $partHeaders = $this->indexHeaders($part['headers'] ?? []);
            $filename = (string)($part['filename'] ?? '');
            $hasContentId = '' !== trim(($partHeaders['content-id'] ?? ''), '<> ');

            if ('' !== $filename || true === $hasContentId || true === $keepAlways) {
                $lazyParts[] = $part;
            }
        }

        foreach ($part['parts'] ?? [] as $subPart) {
            $child = $this->extractBody($subPart);

            if ('' === $bodyText) {
                $bodyText = $child->bodyText;
            }
            if ('' === $bodyHtml) {
                $bodyHtml = $child->bodyHtml;
            }

            $lazyParts   = array_merge($lazyParts, $child->lazyParts);
            $inlineParts = array_merge($inlineParts, $child->inlineParts);
        }

        return new ExtractedBody($bodyText, $bodyHtml, $lazyParts, $inlineParts);
    }

    /**
     * Read a body part's declared charset and convert its bytes to UTF-8.
     *
     * base64url only undoes the transfer encoding; what comes out is still in
     * whatever charset the sender used, and until now nothing looked. The
     * part's headers were consulted only to decide whether an attachment was
     * inline — never for the body — so a German sender's `Content-Type:
     * text/html; charset=ISO-8859-1` was stored byte for byte, 0xFC and all.
     *
     * That is not a mojibake bug. Postgres rejects the byte outright, so the
     * INSERT failed and took the rest of its batch with it: the message did
     * not arrive looking wrong, it did not arrive. Nothing in the log named a
     * charset.
     *
     * Gmail-only. The IMAP path has always honoured the declared part charset,
     * inside webklex's MessageDecoder::getEncoding().
     *
     * The mimeType field cannot be used for this — Gmail reports the bare type
     * there ("text/html") with the parameters left on the header.
     *
     * @param array<string,mixed> $part
     */
    private function toUtf8(string $bytes, array $part): string
    {
        $headers = $this->indexHeaders($part['headers'] ?? []);

        return CharsetHelper::toUtf8(
            $bytes,
            CharsetHelper::charsetFromContentType($headers['content-type'] ?? null),
        );
    }

    /**
     * Persist parts whose bytes came inline in the payload.
     *
     * No `gmail://` stub and no lazy fetch: Gmail already sent the content, so
     * storagePath is a real path from the start and AttachmentResolver never
     * has to go back for it. MessagePart has no body column and does not need
     * one — the bytes go where every other attachment's do.
     *
     * The bucket key mirrors AttachmentResolver::materialise(): an API-synced
     * message has no mailbox and no IMAP UID, so 0 and a hash of the Gmail id
     * stand in for them.
     *
     * Marked inline deliberately. persistAttachmentStubs() derives
     * hasAttachments from the non-inline parts, and an invite counted as an
     * attachment would put a paperclip and an "invite.ics" chip on every
     * meeting in the thread view. Extraction finds these by content type, not
     * by disposition, so nothing is lost by hiding them.
     *
     * @param list<array{part: array<string,mixed>, bytes: string}> $inlineParts
     */
    private function persistInlineParts(array $inlineParts, Message $message, Account $account): void
    {
        foreach ($inlineParts as $entry) {
            $part  = $entry['part'];
            $bytes = $entry['bytes'];

            // Decoded here rather than where $filename is assigned to the part,
            // so the name written to disk and the name written to the row are
            // the same one. See persistAttachmentStubs() for why it needs doing.
            $filename = MimeHeaderHelper::decode((string)($part['filename'] ?? ''));

            if ('' === $filename) {
                $filename = 'invite.ics';
            }

            try {
                $relativePath = $this->attachmentStorage->store(
                    (int) $account->id,
                    0,
                    abs(crc32((string) $message->gmailId)),
                    $filename,
                    $bytes,
                );
            } catch (\Throwable $e) {
                // A part we could not store is a missed event, never a failed
                // import — the message itself is fine.
                $this->logger->warning('GmailMessageBuilder: inline part not stored', [
                    'gmailId'   => $message->gmailId,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);

                continue;
            }

            $mp = new MessagePart();
            $mp->message     = $message;
            $mp->contentType = (string)($part['mimeType'] ?? 'application/octet-stream');
            $mp->filename    = $filename;
            $mp->disposition = 'inline';
            $mp->size        = strlen($bytes);
            $mp->storagePath = $relativePath;
            $mp->isInline    = true;

            $this->em->persist($mp);
        }
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
            // The subject went through MimeHeaderHelper and the filename did
            // not, which is the same header rules applied to two fields off
            // the same message. An encoded word left alone is only ugly
            // ("=?ISO-8859-1?Q?Geb=FChren.pdf?=" in the chip), but a raw 8-bit
            // filename — a Windows client's "Übersicht.pdf", unencoded — is
            // invalid UTF-8 reaching a UTF-8 column, and that is a rejected
            // INSERT rather than a cosmetic problem.
            $filename = MimeHeaderHelper::decode((string)($part['filename'] ?? 'attachment'));
            $contentType = (string)($part['mimeType'] ?? 'application/octet-stream');
            $attachmentId = (string)($part['body']['attachmentId'] ?? '');
            $size = (int)($part['body']['size'] ?? 0);

            $contentId = $this->inlineDetector->normalizeContentId($partHeaders['content-id'] ?? null);
            $isInline = $this->inlineDetector->isInline(
                $partHeaders['content-disposition'] ?? null,
                $contentId,
                $bodyHtml,
            );

            $mp = new MessagePart();
            $mp->message     = $message;
            $mp->contentType = $contentType;
            $mp->filename    = $filename;
            $mp->contentId   = '' !== $contentId ? $contentId : null;
            $mp->disposition = $isInline ? 'inline' : 'attachment';
            $mp->size        = $size;
            $mp->storagePath = 'gmail://' . $attachmentId;
            $mp->isInline    = $isInline;

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

    /**
     * Labels are user-scoped, so a label resolved against the carrier account
     * IS the label the target account should carry — there is nothing to
     * translate. What the target still needs is its own binding, so the label
     * knows it is materialized there too.
     *
     * This used to rebuild the label under the target account by role or by
     * full path, which is precisely the per-account duplication the unified
     * model removes.
     */
    private function translateLabel(Label $label, Account $target): Label
    {
        $this->localLabelResolver->binding($label, $target);

        return $label;
    }

    /**
     * Make the message's labels agree with what Gmail says they are.
     *
     * Authoritative, which it was not. This resolved the ids Gmail reported and
     * added every one of them, and nothing ever came off — so unfiling a
     * message in Gmail left the label on it here permanently, and since
     * archiving in Gmail *is* the removal of INBOX, a message archived in the
     * web interface went on showing in plMail's inbox forever. Additive was the
     * safe half of a rule whose other half had not been written.
     *
     * Authoritative only within the partition Gmail speaks for, though, and
     * that is what GmailLabelPolicy is for. Snoozed is plMail's own, Archive
     * has no Gmail counterpart, and a user may keep labels here that exist
     * nowhere else; a rule that could not tell the difference would answer the
     * first archive by deleting the user's local filing. So the removal is
     * confined to labels carrying a gmailLabelId on the carrier account — the
     * ones this feed is entitled to have an opinion about.
     *
     * The carrier is the account whose API produced $labelIds, and it is
     * deliberately the account the policy is asked about, never $target. Gmail
     * speaks for its own mailbox: a label that exists on a sibling account and
     * not on this one is one this feed has said nothing about, and silence is
     * not a removal.
     *
     * Shared by the import path (new messages, where nothing is on the message
     * yet and the removal pass finds nothing to do) and the enrichment path
     * (existing rows, where it is the entire point).
     *
     * @param list<string> $labelIds
     */
    public function applyTranslatedLabels(Message $message, array $labelIds, Account $target, Account $carrier): void
    {
        $resolved = $this->labelResolver->resolve($labelIds, $carrier);

        /** @var array<int,true> $keep */
        $keep = [];

        foreach ($resolved as $label) {
            $keep[(int) $label->id] = true;
        }

        $inbox    = $this->localLabelResolver->systemLabel(LabelRole::Inbox, $target);
        $hadInbox = $message->hasLabel($inbox);

        // Off first, so that a label being moved between messages cannot be
        // added and then removed by its own pass.
        foreach ($this->labelPolicy->providerLabels($message, $carrier) as $current) {
            if (true === isset($keep[(int) $current->id])) {
                continue;
            }

            $message->removeLabel($current);
        }

        foreach ($resolved as $label) {
            $message->addLabel($this->translateLabel($label, $target));
        }

        $this->reconcileArchive($message, $labelIds, $target, $hadInbox, $message->hasLabel($inbox));
    }

    /**
     * Give a message archived in Gmail the Archive label, and take it back off
     * when it returns to the inbox.
     *
     * Gmail has no Archive label — archiving there is the removal of INBOX and
     * nothing else — so the authoritative label application above correctly
     * takes the message out of the inbox and leaves it wearing nothing that
     * says where it went. plMail's Archive view is a label, so the message
     * archived in Gmail simply did not appear in it: out of the inbox, out of
     * the archive, reachable only through search or its conversation. The two
     * sides have to agree regardless of which of them did the archiving.
     *
     * Written as a *transition* rather than a state, and that is the whole of
     * its safety. "Has no INBOX" is true of Sent mail, of drafts, of every
     * message on an account that has never had an inbox label — inferring
     * Archive from the state would put the label on all of them. What this
     * responds to is INBOX having been there and now being gone, which is an
     * event only an archive (or a trash, or a spam move) produces.
     *
     * It therefore also does not backfill: messages archived in Gmail before
     * this shipped never make the transition, because plMail never saw them in
     * the inbox to begin with. A resync is what puts those right.
     *
     * Trash, spam and snoozed are excluded because each is a destination in its
     * own right and already says where the message went. Archive means "left
     * the inbox and went nowhere in particular", which is precisely the case
     * with nothing else to say about it.
     *
     * @param list<string> $labelIds
     */
    private function reconcileArchive(
        Message $message,
        array   $labelIds,
        Account $target,
        bool    $hadInbox,
        bool    $hasInbox,
    ): void {
        $archive = $this->localLabelResolver->systemLabel(LabelRole::Archive, $target);

        // Back to the inbox: whatever archived it has been undone, and the
        // Archive label is the thing that would otherwise survive it.
        if (false === $hadInbox && true === $hasInbox) {
            $message->removeLabel($archive);

            return;
        }

        if (false === $hadInbox || true === $hasInbox) {
            return;
        }

        foreach (['TRASH', 'SPAM'] as $elsewhere) {
            if (true === in_array($elsewhere, $labelIds, true)) {
                return;
            }
        }

        if (true === $message->hasLabel($this->localLabelResolver->systemLabel(LabelRole::Snoozed, $target))) {
            return;
        }

        $message->addLabel($archive);
    }
}
