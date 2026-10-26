<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\PlaintextSource;
use App\Domain\Exception\DecryptedMailRefused;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\MimeHeaderHelper;
use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Ai\AiFeature;
use App\Entity\Calendar\EventProposal;
use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Infrastructure\Messaging\Message\ExtractEventsMessage;
use App\Infrastructure\Messaging\Message\ExtractInsightsMessage;
use App\Infrastructure\Messaging\Message\ProposeEventsMessage;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\ParsedAttachmentWriter;
use App\Service\Rule\MailRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use Webklex\PHPIMAP\Config;

/**
 * The decrypted text of end-to-end encrypted mail, for an account that keeps
 * it on the server (Account::$keepDecrypted) — phase 3b of issue #28.
 *
 * A browser that holds the key decrypts the message and hands over the inner
 * MIME message whole (POST /mail/message/{id}/plaintext). Nothing it says is
 * taken on trust: the MIME is parsed here, the HTML is sanitised here, the
 * attachments are stored as any synced mail's are — and then the mail gets
 * what any other mail gets after ingest: the search index (search_vector is
 * generated from body_text), insights, date proposals, events from `.ics`
 * parts, and the person's rules while it is still unread. The models only as
 * far as DecryptedMailPolicy::allowsAi() says.
 *
 * The raw source stays the ciphertext that arrived. The decrypted message is
 * kept beside it (SealedMessageStorage::DECRYPTED), and forget() takes all of
 * it back out again when the switch is turned off.
 */
final readonly class DecryptedMail
{
    /** A decrypted message with its attachments; Gmail accepts 25 MB of them. */
    public const int MAX_BYTES = 48 * 1024 * 1024;

    public function __construct(
        private EntityManagerInterface   $entityManager,
        private SealedMessageStorage     $storage,
        private ParsedAttachmentWriter   $attachments,
        private AttachmentStorageHelper  $files,
        private MailBodySanitizer        $sanitizer,
        private DraftAttachmentService   $parts,
        private MailChangeRecorder       $changes,
        private MessageBusInterface      $bus,
        private MailRuleEngine           $rules,
        private DecryptedMailPolicy      $policy,
    ) {
    }

    /**
     * Take a browser's decrypted copy of an encrypted message.
     *
     * Idempotent: a message that already has its decrypted body is left as it
     * is, so two open tabs working through the same backlog cost nothing.
     *
     * @throws DecryptedMailRefused when this message may not, or cannot, be
     *                              given a decrypted body
     */
    public function accept(Message $message, string $mime): void
    {
        if (false === $this->policy->keeps($message->account)) {
            throw new DecryptedMailRefused('the account does not keep decrypted mail');
        }

        if (true !== $message->crypto?->isEncrypted() || true === $message->sealedDraft || null === $message->id) {
            throw new DecryptedMailRefused('not an encrypted message');
        }

        if (PlaintextSource::Client === $message->plaintextSource) {
            return;
        }

        if ('' === trim($mime) || strlen($mime) > self::MAX_BYTES) {
            throw new DecryptedMailRefused('no message, or too large');
        }

        try {
            $parsed = MessageParser::fromString(
                $mime,
                Config::make(['decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]]]),
            );
        } catch (Throwable $exception) {
            throw new DecryptedMailRefused('not a MIME message: ' . $exception->getMessage());
        }

        $message->bodyText = (string) $parsed->getTextBody();
        $message->bodyHtml = (string) $parsed->getHTMLBody();

        // Header protection (RFC 9788): the outside said "...", and the real
        // subject is in here. Taken for the row — and the conversation, while
        // it is the placeholder too — so the list and search show it.
        $innerSubject = trim(MimeHeaderHelper::decode((string) $parsed->getSubject()));

        if ('' !== $innerSubject && true === self::isPlaceholder($message->subject)) {
            $message->subject = $innerSubject;

            if (null !== $message->thread && true === self::isPlaceholder($message->thread->subject)) {
                $message->thread->subject = $innerSubject;
            }
        }

        foreach ($parsed->getAttachments() as $attachment) {
            // A signature made inside the encryption is not an attachment
            // anybody wants, any more than one made outside it is.
            if ('application/pgp-signature' === strtolower((string) $attachment->getContentType())) {
                continue;
            }

            $this->attachments->write($message, $attachment);
        }

        $message->plaintextSource = PlaintextSource::Client;

        // The parts first: sanitize() points the body's cid: images at the
        // parts' own URLs, and those need the ids a flush gives them.
        $this->entityManager->flush();
        $this->sanitizer->sanitize($message);
        $this->parts->syncFlag($message);
        $this->storage->write($message->id, SealedMessageStorage::DECRYPTED, $mime);

        $this->changes->emailChanged((int) $message->account->id, (string) $message->id, false, $message->thread);
        $this->entityManager->flush();

        $this->process($message);
    }

    /**
     * Take the decrypted text back out of a message: its body, the
     * attachments that came with it, everything worked out from it — search
     * index, embeddings, insights, date proposals, the thread's summary — and
     * the decrypted copy itself. What stays is the ciphertext, and events
     * already in a calendar, which are the person's now.
     *
     * Flushes nothing; the caller does, in batches.
     */
    public function forget(Message $message): void
    {
        if (PlaintextSource::Client !== $message->plaintextSource || null === $message->id) {
            return;
        }

        foreach ($message->messageParts->toArray() as $part) {
            if (false === $part->sealed) {
                $this->files->delete($part->storagePath);
                $message->removeMessagePart($part);
                $this->entityManager->remove($part);
            }
        }

        $message->bodyText        = null;
        $message->bodyHtml        = null;
        $message->bodyHtmlSafe    = null;
        $message->hasAttachments  = false;
        $message->plaintextSource = PlaintextSource::None;

        $this->storage->delete($message->id, SealedMessageStorage::DECRYPTED);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM message_embedding WHERE message_id = ?', [$message->id]);

        if (null !== $message->thread?->id) {
            $connection->executeStatement('DELETE FROM thread_summary WHERE thread_id = ?', [$message->thread->id]);
        }

        $this->entityManager->createQuery('DELETE FROM ' . MailInsight::class . ' i WHERE i.message = :message')
            ->setParameter('message', $message)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . EventProposal::class . ' p WHERE p.message = :message')
            ->setParameter('message', $message)->execute();

        $this->changes->emailChanged((int) $message->account->id, (string) $message->id, false, $message->thread);
    }

    /**
     * Whether a subject is only what a sender puts outside when the real one
     * is encrypted — "..." from Thunderbird and plMail, "[...]" from RFC 9788,
     * "Encrypted Message" from some others. The browser's isPlaceholder() in
     * assets/crypto/message.js says the same.
     */
    public static function isPlaceholder(?string $subject): bool
    {
        return 1 === preg_match('/^\s*(\.\.\.|\[\.\.\.\]|encrypted message)\s*$/i', (string) $subject);
    }

    /**
     * What the models made of an account's decrypted mail, gone: its
     * embeddings and the summaries of its threads. For the models switch
     * being turned off while the mail itself stays readable.
     */
    public function forgetModelOutput(Account $account): void
    {
        $connection = $this->entityManager->getConnection();
        $client     = PlaintextSource::Client->value;

        $connection->executeStatement(
            'DELETE FROM message_embedding WHERE message_id IN (SELECT id FROM message WHERE account_id = ? AND plaintext_source = ?)',
            [$account->id, $client],
        );
        $connection->executeStatement(
            'DELETE FROM thread_summary WHERE thread_id IN (SELECT thread_id FROM message WHERE account_id = ? AND plaintext_source = ? AND thread_id IS NOT NULL)',
            [$account->id, $client],
        );
    }

    /**
     * What any mail gets after it has been ingested, now that this one can be
     * read. Rules only while it is unread: a rule that moved a message the
     * person had already read would be moving it out from under them.
     */
    private function process(Message $message): void
    {
        $ids = [(int) $message->id];

        $this->bus->dispatch(new ExtractInsightsMessage($ids));
        $this->bus->dispatch(new ProposeEventsMessage($ids));
        $this->bus->dispatch(new ExtractEventsMessage($ids));

        if (true === $this->policy->allowsAi($message, AiFeature::Categorise)) {
            $this->bus->dispatch(new ClassifyMailMessage($ids));
        }

        if (null === $message->seenAt) {
            $this->rules->applyToBatch([$message], $message->account);
            $this->entityManager->flush();
        }
    }
}
