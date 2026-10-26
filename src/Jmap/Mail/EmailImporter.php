<?php

declare(strict_types=1);

namespace App\Jmap\Mail;

use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\MimeHeaderHelper;
use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Jmap\Blob\BlobId;
use App\Jmap\Blob\BlobResolver;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\Label\LabelBindingRepository;
use App\Service\Crypto\MessageCryptoDetector;
use App\Service\Crypto\SealGuard;
use App\Service\Imap\MessageSyncer;
use App\Service\Imap\MessageThreader;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\DraftPersister;
use App\Service\Mail\HeaderNormalizer;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\ParsedAttachmentWriter;
use App\Service\Mail\RawMessageResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * One message for JMAP Email/import (RFC 8621 §4.8): an uploaded RFC 5322
 * message becomes an Email, the way the sync would have made it — headers,
 * crypto classification (an encrypted message keeps no body), attachments,
 * the raw bytes as its blob — under the mailboxes and keywords the client
 * names.
 *
 * Written for the case that made plMail need it (issue #28, phase 6): a
 * native client builds and encrypts a message itself, imports it as a
 * `$draft`, and sends it with EmailSubmission/set. For a draft, the message
 * as uploaded is also kept as the message to send — its Content-* headers and
 * body, byte for byte, the outer headers rebuilt from the row as for any
 * mail, exactly what the web composer hands over (SealedDrafts). An
 * encrypted draft names the keys it was encrypted to in the plMail argument
 * `fingerprints`, and is refused unless they are the recipients' current
 * ones (SealGuard); the same check runs again when it is sent.
 *
 * Imported mail lives in plMail; nothing is appended to the provider's
 * mailbox.
 */
final readonly class EmailImporter
{
    /** As the upload endpoint allows. */
    private const int MAX_BYTES = 50_000_000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BlobResolver           $blobs,
        private MessageCryptoDetector  $crypto,
        private HeaderNormalizer       $headers,
        private ParsedAttachmentWriter $attachments,
        private MailBodySanitizer      $sanitizer,
        private DraftPersister         $drafts,
        private DraftAttachmentService $parts,
        private MessageThreader        $threader,
        private LabelBindingRepository $bindings,
        private ThreadLabelSynchronizer $threadLabels,
        private RawMessageResolver     $raw,
        private SealedMessageStorage   $sealed,
        private SealGuard              $guard,
        private MailChangeRecorder     $changes,
    ) {
    }

    /**
     * @param array<string, mixed> $import one EmailImport object
     *
     * @throws MethodException a SetError for notCreated
     */
    public function import(Account $account, array $import): Message
    {
        $blobId = is_string($import['blobId'] ?? null) ? $import['blobId'] : '';
        $parsed = BlobId::parse($blobId);
        $blob   = null === $parsed ? null : $this->blobs->resolve($account, $parsed);

        if (null === $blob) {
            throw new MethodException('blobNotFound', 'No such blob in this account.', ['notFound' => [$blobId]]);
        }

        $mailboxIds = $import['mailboxIds'] ?? null;
        $keywords   = $import['keywords'] ?? [];

        if (false === is_array($mailboxIds) || [] === $mailboxIds || false === is_array($keywords)) {
            throw new MethodException('invalidProperties', '"mailboxIds" must name at least one mailbox, and "keywords" must be a map.', ['properties' => ['mailboxIds', 'keywords']]);
        }

        $raw = true === $blob->isFile() ? (string) file_get_contents((string) $blob->path) : (string) $blob->content;

        if ('' === $raw || strlen($raw) > self::MAX_BYTES) {
            throw new MethodException('invalidProperties', 'The blob is empty or too large to be a message.', ['properties' => ['blobId']]);
        }

        try {
            $mime = MessageParser::fromString($raw, Config::make(['decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]]]));
        } catch (Throwable $exception) {
            throw new MethodException('invalidProperties', 'The blob is not an RFC 5322 message: ' . $exception->getMessage(), ['properties' => ['blobId']]);
        }

        $draft   = true === ($keywords['$draft'] ?? false);
        $labels  = true === $draft ? [] : $this->labels($account, $mailboxIds);
        $message = $this->build($account, $mime, $draft, $import['receivedAt'] ?? null);

        $kind = null;
        $seal = null;

        if (true === $draft) {
            $kind = match (true) {
                true === $message->crypto?->isEncrypted() => SealGuard::KIND_ENCRYPTED,
                null !== $message->crypto => SealGuard::KIND_SIGNED,
                default => SealGuard::KIND_PLAIN,
            };
            $scheme = null !== $message->crypto && false === $message->crypto->isPgp() ? SealGuard::SCHEME_SMIME : SealGuard::SCHEME_PGP;

            $seal = [
                'kind'         => $kind,
                'scheme'       => $scheme,
                'recipients'   => SealGuard::recipientsOf($message),
                'fingerprints' => self::fingerprints($import['fingerprints'] ?? []),
                'signer'       => is_string($import['signer'] ?? null) ? strtolower($import['signer']) : '',
            ];

            if (SealGuard::KIND_ENCRYPTED === $kind && null !== $this->guard->keyRefusal($account->usr, $seal['recipients'], $seal['fingerprints'], $scheme)) {
                throw new MethodException('forbidden', 'The message is not encrypted to every recipient\'s current key; see ContactKey/lookup.', ['properties' => ['fingerprints']]);
            }

            // An S/MIME signature names the certificate that made it, and it
            // has to be the person's current one for the From address.
            if (SealGuard::SCHEME_SMIME === $scheme && null !== $this->guard->signerRefusal($account, (string) $message->fromAddress, $seal['signer'])) {
                throw new MethodException('forbidden', 'The message is not signed with the current S/MIME certificate of its From address; see Keyring/get.', ['properties' => ['signer']]);
            }
        }

        $this->store($account, $message, $draft, $labels, $keywords);

        foreach ($mime->getAttachments() as $attachment) {
            if (null === $message->crypto || false === $this->crypto->isWrapping($message->crypto, $attachment->getContentType(), $attachment->getFilename())) {
                $this->attachments->write($message, $attachment);
            }
        }

        $this->entityManager->flush();
        $this->sanitizer->sanitize($message);
        $this->parts->syncFlag($message);
        $this->raw->store($message, $raw);

        if (null !== $seal) {
            $this->sealed->write((int) $message->id, SealedMessageStorage::OUTGOING, self::entity($raw));
            $message->outgoingSeal = $seal;
        }

        $this->changes->emailChanged((int) $account->id, (string) $message->id, true, $message->thread);
        $this->entityManager->flush();

        return $message;
    }

    private function build(Account $account, ImapMessage $mime, bool $draft, mixed $receivedAt): Message
    {
        $message = new Message();
        $message->account   = $account;
        $message->messageId = MessageIdHelper::normalise((string) $mime->getMessageId());
        $message->subject   = MimeHeaderHelper::decode((string) $mime->getSubject());

        $from = $mime->getFrom()->first();

        if (false !== $from && null !== $from) {
            $message->fromAddress = strtolower(trim((string) ($from->mail ?? '')));
            $message->fromName    = MimeHeaderHelper::decode((string) ($from->personal ?? ''));
        }

        $message->toAddresses  = MessageSyncer::addressesOf($mime->getTo());
        $message->ccAddresses  = MessageSyncer::addressesOf($mime->getCc());
        $message->bccAddresses = MessageSyncer::addressesOf($mime->getBcc());

        $inReplyTo  = $mime->getInReplyTo();
        $references = $mime->getReferences();
        $message->inReplyTo  = $inReplyTo->exist() ? MessageIdHelper::normaliseList((string) $inReplyTo) : [];
        $message->references = $references->exist() ? MessageIdHelper::normaliseList((string) $references) : [];

        $headers = [];

        foreach ($mime->getHeader()->getAttributes() as $name => $attribute) {
            $values = $attribute->toArray();
            $headers[(string) $name] = 1 === count($values) ? (string) reset($values) : array_map(static fn ($value): string => (string) $value, $values);
        }

        $message->headers  = $this->headers->normalize($headers);
        $message->bodyText = (string) $mime->getTextBody();
        $message->bodyHtml = (string) $mime->getHTMLBody();

        $this->crypto->seal($message, $this->crypto->classify(
            $this->headers->first($message->headers, 'content-type'),
            $message->bodyText,
            $message->bodyHtml,
        ));

        // In UTC, like every other date on the row. The columns have no zone of
        // their own, so a value is stored as the wall time of whatever zone it
        // carries: a Date header of "14:53 +0100" kept as it stands was read
        // back as 14:53 UTC, and shown an hour late in Berlin — two in summer.
        // The sync has always converted (MessageSyncer::toUtc()); this did not.
        $date = $mime->getDate()->first();
        $sent = $date instanceof \DateTimeInterface ? MessageSyncer::toUtc($date) : null;

        // A draft has not been sent, whatever its Date header says.
        $message->sentAt     = true === $draft ? null : $sent;
        $message->receivedAt = is_string($receivedAt) ? MessageSyncer::toUtc(new DateTimeImmutable($receivedAt)) : ($sent ?? new DateTimeImmutable());
        $message->hasAttachments = false;
        $message->flags          = [];

        return $message;
    }

    /**
     * The labels the mailboxIds name, refusing any the account does not have —
     * before anything is written. Mailbox ids are label binding ids.
     *
     * @param array<mixed, mixed> $mailboxIds
     *
     * @return list<LabelBinding>
     */
    private function labels(Account $account, array $mailboxIds): array
    {
        $wanted = [];

        foreach ($mailboxIds as $mailboxId => $enabled) {
            $binding = true === $enabled && ctype_digit((string) $mailboxId)
                ? ($this->bindings->findForAccountAndIds((int) $account->id, [(int) $mailboxId])[0] ?? null)
                : null;

            if (true === $enabled && null === $binding) {
                throw new MethodException('invalidProperties', sprintf('No such Mailbox "%s".', $mailboxId), ['properties' => ['mailboxIds']]);
            }

            if (null !== $binding) {
                $wanted[] = $binding;
            }
        }

        if ([] === $wanted) {
            throw new MethodException('invalidProperties', 'An Email must belong to at least one Mailbox.', ['properties' => ['mailboxIds']]);
        }

        return $wanted;
    }

    /**
     * Imported mail is plMail's own: its labels and read state are set here,
     * not proposed to the provider, which has never seen the message.
     *
     * @param list<LabelBinding>  $labels
     * @param array<mixed, mixed> $keywords
     */
    private function store(Account $account, Message $message, bool $draft, array $labels, array $keywords): void
    {
        if (true === $draft) {
            // Drafts live under Drafts, as every other draft does; the
            // composer's own bookkeeping, not a client's choice of mailbox.
            $this->drafts->fileUnderAccount($message, $account);
            $message->addFlag(MessageFlag::DRAFT);
            $message->seenAt = new DateTimeImmutable();
            $this->drafts->storeAndThread($message, $account);

            return;
        }

        foreach ($labels as $binding) {
            if (null !== $binding->label) {
                $message->addLabel($binding->label);
            }

            $message->mailbox ??= $binding->mailbox;
        }

        $message->seenAt    = true === ($keywords['$seen'] ?? false) ? new DateTimeImmutable() : null;
        $message->starredAt = true === ($keywords['$flagged'] ?? false) ? new DateTimeImmutable() : null;

        $this->entityManager->persist($message);
        $this->threader->assignThread($message, $account);

        if (null !== $message->thread) {
            $message->thread->addMessage($message);
            $this->threadLabels->sync($message->thread);
        }

        $this->entityManager->flush();
    }

    /**
     * The message's own body, for sending as it is: its Content-* headers and
     * everything after the blank line. The outer headers are written again
     * when it leaves (MessageSendService), as for any message.
     */
    public static function entity(string $raw): string
    {
        if (false === str_contains($raw, "\r\n\r\n") && true === str_contains($raw, "\n\n")) {
            $raw = (string) preg_replace("/\r?\n/", "\r\n", $raw);
        }

        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');

        $kept    = [];
        $keeping = false;

        foreach (explode("\r\n", $head) as $line) {
            if (1 === preg_match('/^[ \t]/', $line)) {
                if (true === $keeping) {
                    $kept[] = $line;
                }

                continue;
            }

            $keeping = 1 === preg_match('/^content-[a-z-]+:/i', $line);

            if (true === $keeping) {
                $kept[] = $line;
            }
        }

        if ([] === $kept) {
            $kept[] = 'Content-Type: text/plain; charset=utf-8';
        }

        return implode("\r\n", $kept) . "\r\n\r\n" . $body;
    }

    /**
     * @return list<string>
     */
    private static function fingerprints(mixed $value): array
    {
        if (false === is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $fingerprint): string => strtolower(trim(is_string($fingerprint) ? $fingerprint : '')), $value),
            static fn (string $fingerprint): bool => 1 === preg_match('/^[0-9a-f]{40,64}$/', $fingerprint),
        ));
    }
}
