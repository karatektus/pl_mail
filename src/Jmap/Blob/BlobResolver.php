<?php

declare(strict_types=1);

namespace App\Jmap\Blob;

use App\Entity\Mail\Account;
use App\Domain\Helper\UploadStorage;
use App\Repository\Mail\MessagePartRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\UploadedBlobRepository;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\MessageSourceBuilder;
use App\Service\Mail\RawMessageResolver;
use Psr\Log\LoggerInterface;

/**
 * Turns a client-supplied blobId into bytes, scoped to one account.
 *
 * Every lookup is filtered by the account, so a blob belonging to another
 * account — or to another user entirely — resolves to null and the caller
 * answers notFound. There is deliberately no "find then check" path where a
 * forgotten check would leak.
 */
final class BlobResolver
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MessagePartRepository $partRepository,
        private readonly UploadedBlobRepository $uploadRepository,
        private readonly UploadStorage $uploadStorage,
        private readonly AttachmentResolver $attachmentResolver,
        private readonly MessageSourceBuilder $sourceBuilder,
        private readonly RawMessageResolver $rawResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(Account $account, BlobId $blobId): ?ResolvedBlob
    {
        if (true === $blobId->isPart()) {
            return $this->part($account, $blobId->id);
        }

        if (true === $blobId->isUpload()) {
            return $this->upload($account, $blobId->id);
        }

        return $this->message($account, $blobId->id);
    }

    private function upload(Account $account, int $uploadId): ?ResolvedBlob
    {
        $blob = $this->uploadRepository->findOneOwnedBy($uploadId, $account);

        if (null === $blob || false === $this->uploadStorage->exists($blob->path)) {
            return null;
        }

        return ResolvedBlob::fromPath(
            $this->uploadStorage->getAbsolutePath($blob->path),
            $blob->contentType,
            sprintf('upload-%d', $uploadId),
        );
    }

    private function part(Account $account, int $partId): ?ResolvedBlob
    {
        $part = $this->partRepository->find($partId);

        if (null === $part) {
            return null;
        }

        $message = $part->message;

        if (null === $message || $message->account->id !== $account->id) {
            return null;
        }

        // absolutePathFor() also handles gmail:// and msgraph:// parts, fetching
        // and caching the bytes on first access. It throws when a provider
        // fetch is impossible (no account, no remote id), and can hand back a
        // path for a local part whose file has since gone missing — both are
        // "this blob is not retrievable", which the caller reports as notFound
        // rather than a 500.
        try {
            $path = $this->attachmentResolver->absolutePathFor($part);
        } catch (\Throwable $exception) {
            $this->logger->warning('JMAP blob download: attachment could not be resolved', [
                'partId'    => $partId,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }

        if (false === is_file($path)) {
            $this->logger->warning('JMAP blob download: attachment missing on disk', [
                'partId' => $partId,
                'path' => $path,
            ]);

            return null;
        }

        return ResolvedBlob::fromPath(
            $path,
            $part->contentType ?? 'application/octet-stream',
            $part->filename ?? 'attachment',
        );
    }

    private function message(Account $account, int $messageId): ?ResolvedBlob
    {
        $messages = $this->messageRepository->findByAccountAndIds((int) $account->id, [$messageId]);
        $message = $messages[0] ?? null;

        if (null === $message) {
            return null;
        }

        $filename = sprintf('message-%d.eml', $messageId);

        // The genuine article when we have it (or can fetch it), which is what
        // a blobId is supposed to be.
        $rawPath = $this->rawResolver->absolutePathFor($message);

        if (null !== $rawPath) {
            return ResolvedBlob::fromPath($rawPath, 'message/rfc822', $filename);
        }

        // Fallback only: a reconstruction from the parsed headers and decoded
        // body. Byte-faithful to neither the transfer encoding nor the MIME
        // structure, so it will not verify a DKIM signature — see
        // MessageSourceBuilder. Reached by plain-IMAP messages synced before
        // raw storage existed, and when a provider fetch fails.
        return ResolvedBlob::fromContent(
            $this->sourceBuilder->build($message),
            'message/rfc822',
            $filename,
        );
    }
}
