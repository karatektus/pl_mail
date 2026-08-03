<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the original RFC822 bytes of a message, fetching them on first
 * access when the provider can supply them.
 *
 * Same shape as AttachmentResolver, and for the same reason: fetching every
 * raw message during sync would double the API traffic of an initial sync of
 * thousands of messages, so the bytes are pulled only when something actually
 * asks for them — a JMAP blob download, a "show original", an Email/import
 * round-trip — and cached on disk from then on.
 *
 * Plain IMAP is the exception: MessageSyncer already holds the raw message
 * while parsing it, so it writes rawPath eagerly. Nothing here re-opens an
 * IMAP connection, which is why an IMAP message synced before this feature
 * existed resolves to null and the caller falls back to a reconstruction.
 */
final class RawMessageResolver
{
    public function __construct(
        private readonly RawMessageStorage $storage,
        private readonly GmailApiClient $gmailApiClient,
        private readonly GraphApiClient $graphApiClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Absolute path to the raw bytes, or null when they cannot be obtained.
     */
    public function absolutePathFor(Message $message): ?string
    {
        $stored = $message->rawPath;

        if (null !== $stored && true === $this->storage->exists($stored)) {
            return $this->storage->getAbsolutePath($stored);
        }

        $content = $this->fetch($message);

        if (null === $content || '' === $content) {
            return null;
        }

        return $this->storage->getAbsolutePath($this->persist($message, $content));
    }

    /**
     * Store raw bytes the caller already has — the sync path, which parses the
     * message and would otherwise throw the original away.
     */
    public function store(Message $message, string $content): void
    {
        if ('' === $content) {
            return;
        }

        $this->persist($message, $content, flush: false);
    }

    private function persist(Message $message, string $content, bool $flush = true): string
    {
        $relativePath = $this->storage->store(
            (int) $message->account->getId(),
            (int) $message->id,
            $content,
        );

        $message->rawPath = $relativePath;

        if (true === $flush) {
            $this->em->flush();
        }

        return $relativePath;
    }

    private function fetch(Message $message): ?string
    {
        $account = $message->account;

        try {
            $gmailId = $message->gmailId;

            if (null !== $gmailId && '' !== $gmailId) {
                return $this->gmailApiClient->getRawMessage($account, $gmailId);
            }

            $graphId = $message->graphId;

            if (null !== $graphId && '' !== $graphId) {
                return $this->graphApiClient->getRawMessage($account, $graphId);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Raw message could not be fetched from the provider', [
                'messageId' => $message->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        // Plain IMAP with no stored raw: pre-dates the feature, or the sync
        // failed to capture it. Callers fall back to a reconstruction.
        return null;
    }
}
