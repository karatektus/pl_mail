<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Jmap\Blob\BlobId;

/**
 * Maps a plMail Message onto a JMAP Email object (RFC 8621 §4).
 *
 * Two shape mismatches are resolved here and nowhere else:
 *
 * 1. mailboxIds comes from Message::getLabels() — the message<->label join is
 *    the authoritative per-message assignment. thread_label is NOT used: it is
 *    a derived union that ThreadLabelSynchronizer recomputes from these rows,
 *    so reading it would report a mailbox for every message in a thread.
 *
 *    Those rows hold user-scoped LABEL ids, but a JMAP Mailbox id is a
 *    per-account LABEL BINDING id (see MailboxMapper) — which is also what
 *    inMailbox and Email/set's mailboxIds patch consume. So the ids are
 *    translated on the way out, via the same label id => binding id map
 *    MailboxMapper uses for parentId. Both are autoincrement ints from
 *    different tables, so emitting the untranslated id does not fail loudly:
 *    it names some unrelated mailbox that happens to share the number.
 *    A label with no binding in this account is omitted rather than emitted
 *    as an id the client cannot resolve.
 *
 * 2. plMail stores a flattened body (bodyText / bodyHtmlSafe) rather than a
 *    MIME part tree, so two synthetic body parts are published with the fixed
 *    partIds "text" and "html". Clients treat partId as opaque, and they stay
 *    stable for a given message, which is all fetchTextBodyValues needs.
 */
final class EmailMapper
{
    /**
     * How much of the text body the "preview" property carries. RFC 8621 caps
     * it at 256 characters.
     */
    private const int PREVIEW_LENGTH = 256;

    private const string TEXT_PART_ID = 'text';
    private const string HTML_PART_ID = 'html';

    /**
     * @param list<string>|null $properties
     * @param list<string>|null $bodyProperties
     * @param array<int,int>    $bindingIdByLabelId label id => binding id, for
     *        this account; used to express mailboxIds in the binding id space
     *
     * @return array<string,mixed>
     */
    public function toJmap(
        Message $message,
        ?array $properties = null,
        bool $fetchTextBodyValues = false,
        bool $fetchHtmlBodyValues = false,
        ?array $bodyProperties = null,
        array $bindingIdByLabelId = [],
    ): array {
        $full = $this->full($message, $fetchTextBodyValues, $fetchHtmlBodyValues, $bodyProperties, $bindingIdByLabelId);

        if (null === $properties) {
            return $full;
        }

        // "id" is always returned regardless of the requested property set.
        $filtered = ['id' => $full['id']];

        foreach ($properties as $property) {
            if (true === array_key_exists($property, $full)) {
                $filtered[$property] = $full[$property];
            }
        }

        return $filtered;
    }

    /**
     * @param list<string>|null $bodyProperties
     * @param array<int,int>    $bindingIdByLabelId
     *
     * @return array<string,mixed>
     */
    private function full(
        Message $message,
        bool $fetchTextBodyValues,
        bool $fetchHtmlBodyValues,
        ?array $bodyProperties,
        array $bindingIdByLabelId,
    ): array {
        $textBody = $this->textBodyParts($message, $bodyProperties);
        $htmlBody = $this->htmlBodyParts($message, $bodyProperties);

        return [
            'id' => (string) $message->getId(),
            'blobId' => (string) BlobId::forMessage((int) $message->getId()),
            'threadId' => $this->threadId($message),
            'mailboxIds' => $this->mailboxIds($message, $bindingIdByLabelId),
            'keywords' => $this->keywords($message),
            'size' => $message->getSize() ?? 0,
            'receivedAt' => $this->utcOrNull($message->getReceivedAt() ?? $message->getSentAt() ?? $message->getCreatedAt()),
            'sentAt' => $this->utcOrNull($message->getSentAt()),
            'messageId' => $this->headerIdList($message->getMessageId()),
            'inReplyTo' => $this->nonEmptyList($message->getInReplyTo()),
            'references' => $this->nonEmptyList($message->getReferences()),
            'subject' => $message->getSubject(),
            'from' => $this->from($message),
            'sender' => null,
            'to' => $this->addressList($message->getToAddresses()),
            'cc' => $this->addressList($message->getCcAddresses()),
            'bcc' => $this->addressList($message->getBccAddresses()),
            'replyTo' => null,
            'hasAttachment' => true === $message->hasAttachments(),
            // plMail extension: the raw classification of *this message*, which
            // is the signal Thread.category is resolved from and not the value a
            // tab is drawn with. Published because a Promotions conversation
            // containing one Primary reply is an ordinary outcome of
            // most-recent-wins, and a client that could not see the messages'
            // own values would have to conclude the classifier was broken.
            //
            // Read-only, and deliberately not accepted as an Email/query
            // condition: filtering it would put that same conversation in two
            // tabs. See EmailFilterCompiler::threadCategory().
            'category' => $message->getCategory()?->value,
            'preview' => $this->preview($message),
            'textBody' => $textBody,
            'htmlBody' => $htmlBody,
            'attachments' => $this->attachments($message, $bodyProperties),
            'bodyValues' => $this->bodyValues($message, $fetchTextBodyValues, $fetchHtmlBodyValues),
        ];
    }

    private function threadId(Message $message): ?string
    {
        $thread = $message->getThread();

        if (null === $thread) {
            return null;
        }

        return (string) $thread->id;
    }

    /**
     * JMAP models set membership as a map id => true, never a list.
     *
     * @param array<int,int> $bindingIdByLabelId
     *
     * @return array<string,bool>|\stdClass
     */
    private function mailboxIds(Message $message, array $bindingIdByLabelId): array|\stdClass
    {
        $ids = [];

        foreach ($message->getLabels() as $label) {
            $bindingId = $bindingIdByLabelId[(int) $label->id] ?? null;

            if (null === $bindingId) {
                continue;
            }

            $ids[(string) $bindingId] = true;
        }

        if (0 === count($ids)) {
            return new \stdClass();
        }

        return $ids;
    }

    /**
     * $seen and $flagged come from the seenAt/starredAt timestamps, which are
     * what the web UI reads and writes. Message::$flags is an IMAP mirror that
     * only the plain-IMAP path populates, so it is authoritative only for the
     * two keywords that have no dedicated column: $draft and $answered.
     *
     * @return array<string,bool>|\stdClass
     */
    private function keywords(Message $message): array|\stdClass
    {
        $keywords = [];

        if (null !== $message->getSeenAt()) {
            $keywords['$seen'] = true;
        }

        if (null !== $message->getStarredAt()) {
            $keywords['$flagged'] = true;
        }

        $flags = $message->getFlags();

        if (true === in_array(MessageFlag::DRAFT->value, $flags, true)) {
            $keywords['$draft'] = true;
        }

        if (true === in_array(MessageFlag::ANSWERED->value, $flags, true)) {
            $keywords['$answered'] = true;
        }

        if (0 === count($keywords)) {
            return new \stdClass();
        }

        return $keywords;
    }

    /**
     * @return list<array{name:?string,email:string}>|null
     */
    private function from(Message $message): ?array
    {
        $address = $message->getFromAddress();

        if (null === $address || '' === $address) {
            return null;
        }

        return [[
            'name' => $message->getFromName(),
            'email' => $address,
        ]];
    }

    /**
     * plMail stores addresses as {name, address}; JMAP EmailAddress is
     * {name, email}.
     *
     * @param array<int,mixed>|null $addresses
     *
     * @return list<array{name:?string,email:string}>|null
     */
    private function addressList(?array $addresses): ?array
    {
        if (null === $addresses || 0 === count($addresses)) {
            return null;
        }

        $list = [];

        foreach ($addresses as $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            $email = $entry['address'] ?? $entry['email'] ?? null;

            if (false === is_string($email) || '' === $email) {
                continue;
            }

            $name = $entry['name'] ?? null;

            $list[] = [
                'name' => is_string($name) && '' !== $name ? $name : null,
                'email' => $email,
            ];
        }

        if (0 === count($list)) {
            return null;
        }

        return $list;
    }

    /**
     * The messageId/inReplyTo/references properties are lists of bare
     * Message-IDs with the angle brackets stripped.
     *
     * @return list<string>|null
     */
    private function headerIdList(?string $messageId): ?array
    {
        if (null === $messageId || '' === $messageId) {
            return null;
        }

        return [trim($messageId, '<>')];
    }

    /**
     * @param array<int,mixed>|null $values
     *
     * @return list<string>|null
     */
    private function nonEmptyList(?array $values): ?array
    {
        if (null === $values || 0 === count($values)) {
            return null;
        }

        $list = [];

        foreach ($values as $value) {
            if (true === is_string($value) && '' !== $value) {
                $list[] = trim($value, '<>');
            }
        }

        if (0 === count($list)) {
            return null;
        }

        return $list;
    }

    private function preview(Message $message): string
    {
        $text = $message->getBodyText();

        if (null === $text || '' === $text) {
            return '';
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($collapsed) <= self::PREVIEW_LENGTH) {
            return $collapsed;
        }

        return mb_substr($collapsed, 0, self::PREVIEW_LENGTH);
    }

    /**
     * @param list<string>|null $bodyProperties
     *
     * @return list<array<string,mixed>>
     */
    private function textBodyParts(Message $message, ?array $bodyProperties): array
    {
        $text = $message->getBodyText();

        if (null === $text || '' === $text) {
            return [];
        }

        return [$this->bodyPart(
            self::TEXT_PART_ID,
            (string) BlobId::forMessage((int) $message->getId()),
            'text/plain',
            strlen($text),
            $bodyProperties,
        )];
    }

    /**
     * The sanitised HTML is published, never the raw column: this body is
     * handed straight to third-party clients that render it.
     *
     * @param list<string>|null $bodyProperties
     *
     * @return list<array<string,mixed>>
     */
    private function htmlBodyParts(Message $message, ?array $bodyProperties): array
    {
        $html = $message->getBodyHtmlSafe();

        if (null === $html || '' === $html) {
            return [];
        }

        return [$this->bodyPart(
            self::HTML_PART_ID,
            (string) BlobId::forMessage((int) $message->getId()),
            'text/html',
            strlen($html),
            $bodyProperties,
        )];
    }

    /**
     * @param list<string>|null $bodyProperties
     *
     * @return list<array<string,mixed>>
     */
    private function attachments(Message $message, ?array $bodyProperties): array
    {
        $attachments = [];

        foreach ($message->getMessageParts() as $part) {
            if (false === $this->isAttachment($part)) {
                continue;
            }

            $attachment = $this->bodyPart(
                (string) $part->id,
                (string) BlobId::forPart((int) $part->id),
                $part->contentType ?? 'application/octet-stream',
                $part->size ?? 0,
                $bodyProperties,
            );

            $attachment['name'] = $part->filename;
            $attachment['cid'] = $this->contentId($part);
            $attachment['disposition'] = true === $part->isInline ? 'inline' : 'attachment';

            $attachments[] = $attachment;
        }

        return $attachments;
    }

    private function isAttachment(MessagePart $part): bool
    {
        $filename = $part->filename;

        if (null !== $filename && '' !== $filename) {
            return true;
        }

        return 'attachment' === $part->disposition;
    }

    private function contentId(MessagePart $part): ?string
    {
        $cid = $part->contentId;

        if (null === $cid || '' === $cid) {
            return null;
        }

        return trim($cid, '<>');
    }

    /**
     * @param list<string>|null $bodyProperties
     *
     * @return array<string,mixed>
     */
    private function bodyPart(
        string $partId,
        string $blobId,
        string $type,
        int $size,
        ?array $bodyProperties,
    ): array {
        $part = [
            'partId' => $partId,
            'blobId' => $blobId,
            'size' => $size,
            'type' => $type,
            'charset' => 'utf-8',
            'name' => null,
            'disposition' => null,
            'cid' => null,
            'language' => null,
            'location' => null,
            'headers' => [],
        ];

        if (null === $bodyProperties) {
            return $part;
        }

        $filtered = ['partId' => $partId];

        foreach ($bodyProperties as $property) {
            if (true === array_key_exists($property, $part)) {
                $filtered[$property] = $part[$property];
            }
        }

        return $filtered;
    }

    /**
     * @return array<string,array{value:string,isEncodingProblem:bool,isTruncated:bool}>|\stdClass
     */
    private function bodyValues(Message $message, bool $fetchText, bool $fetchHtml): array|\stdClass
    {
        $values = [];

        if (true === $fetchText) {
            $text = $message->getBodyText();

            if (null !== $text && '' !== $text) {
                $values[self::TEXT_PART_ID] = $this->bodyValue($text);
            }
        }

        if (true === $fetchHtml) {
            $html = $message->getBodyHtmlSafe();

            if (null !== $html && '' !== $html) {
                $values[self::HTML_PART_ID] = $this->bodyValue($html);
            }
        }

        if (0 === count($values)) {
            return new \stdClass();
        }

        return $values;
    }

    /**
     * @return array{value:string,isEncodingProblem:bool,isTruncated:bool}
     */
    private function bodyValue(string $value): array
    {
        return [
            'value' => $value,
            'isEncodingProblem' => false,
            'isTruncated' => false,
        ];
    }

    private function utcOrNull(?\DateTimeImmutable $date): ?string
    {
        if (null === $date) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
