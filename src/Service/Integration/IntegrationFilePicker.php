<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\DTO\Integration\PickerTransfer;
use App\Domain\DTO\Integration\PickerView;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Exception\IntegrationException;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Interface\TimelineDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\AttachmentResolver;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Everything the file picker asks a connected service to do: read a folder,
 * fetch a preview, pull files into a draft, push one back out.
 *
 * All four share the same failure discipline, which is why they are together. A
 * service being down is a fact about the connection, so it is recorded on the
 * Integration where the settings list can show it — but it is never an
 * exception the picker propagates, because the picker has to keep rendering.
 * The user gets a reason where the files would have been, not a 500.
 *
 * Capability checks live here too. Whether a driver can search, share or
 * summarise a timeline is decided by what the user enabled *and* what the class
 * implements, and asking only one of those questions is how a search box ends
 * up above a driver with no search.
 */
final readonly class IntegrationFilePicker
{
    public function __construct(
        private IntegrationDriverRegistry $drivers,
        private AttachmentStorageHelper   $attachmentStorage,
        private AttachmentResolver        $attachmentResolver,
        private EntityManagerInterface    $em,
    ) {
    }

    /**
     * One folder, or one search within it.
     *
     * Search is handed the folder it was launched from, because in some views
     * it means something else — Immich's people view filters faces by name
     * rather than searching photos.
     */
    public function browse(
        Integration $integration,
        ?string $folderId,
        ?string $cursor,
        ?string $query,
    ): PickerView {
        $driver    = $this->drivers->forIntegration($integration);
        $canSearch = $integration->supports(Capability::Search) && $driver instanceof SearchableDriverInterface;

        try {
            $listing = null !== $query && true === $canSearch
                ? $driver->search($integration, $query, $folderId, $cursor)
                : $driver->list($integration, $folderId, $cursor);
            $error = null;
        } catch (IntegrationException $e) {
            $integration->recordFailure($e->getMessage());
            $this->em->flush();

            $listing = null;
            $error = $e->getMessage();
        }

        return new PickerView(
            $listing,
            $error,
            $canSearch,
            $this->buckets($integration, $driver, $folderId, $query),
        );
    }

    /**
     * A preview for one file, or null where the service has none.
     *
     * Plenty of things legitimately have no preview — a zip, a person Immich
     * never generated a face crop for — so a failure here is not worth
     * recording against the connection.
     */
    public function preview(Integration $integration, string $fileId): ?RemoteFile
    {
        try {
            return $this->drivers->forIntegration($integration)->thumbnail($integration, $fileId);
        } catch (IntegrationException) {
            return null;
        }
    }

    /**
     * Pull a picker selection into a draft.
     *
     * Two ways to attach, chosen per file. `copy` fetches the bytes and makes a
     * real MessagePart, so the mail travels complete and the recipient needs
     * nothing from the service; `link` asks the driver for a public URL, which
     * is the only option above the size cap.
     *
     * One bad file never stops the rest: each is tried on its own and the
     * failures come back as a list, because a selection of five that silently
     * became three is worse than one that says which two did not make it.
     *
     * @param array<string,string> $selection fileId => 'copy'|'link'
     * @param int                  $maxBytes  per-file ceiling for copies, the
     *                                        same one a local upload obeys
     */
    public function pullIntoDraft(
        Integration $integration,
        Message $draft,
        array $selection,
        int $maxBytes,
    ): PickerTransfer {
        $driver = $this->drivers->forIntegration($integration);

        /** @var list<array{name:string,url:string}> $links */
        $links = [];
        /** @var list<string> $errors */
        $errors = [];
        $attached = 0;

        foreach ($selection as $fileId => $mode) {
            $fileId = (string) $fileId;

            try {
                if ('link' === $mode) {
                    $url = $driver->shareLink($integration, $fileId);

                    if (null === $url) {
                        $errors[] = basename($fileId);

                        continue;
                    }

                    $links[] = ['name' => basename($fileId), 'url' => $url];

                    continue;
                }

                $file = $driver->download($integration, $fileId);

                if ($file->size() > $maxBytes) {
                    $errors[] = $file->filename;

                    continue;
                }

                $this->storePart($draft, $file->filename, $file->mime, $file->contents);
                ++$attached;
            } catch (IntegrationException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($attached > 0) {
            // hasAttachments drives the paperclip in the message list and the
            // thread header; a part added without it leaves the draft claiming
            // it has none.
            $draft->hasAttachments = true;
            $this->em->flush();
        }

        return new PickerTransfer($links, $errors, $attached);
    }

    /**
     * Send an attachment the other way: out of a message and into a service.
     *
     * AttachmentResolver is what makes this work for provider-hosted mail — a
     * gmail:// or msgraph:// part is materialised on first access, so a Gmail
     * attachment that has never touched our disk uploads exactly like a locally
     * stored one.
     *
     * @param string|null $folder the Integration setting naming the folder or
     *                            album uploads land in; null means the
     *                            service's own default
     *
     * @return string|null the reason it failed, or null when it worked
     */
    public function pushAttachment(Integration $integration, MessagePart $part, ?string $folder): ?string
    {
        $filename = (string) ($part->filename ?: 'attachment');

        try {
            $this->drivers->forIntegration($integration)->upload(
                $integration,
                $this->attachmentResolver->absolutePathFor($part),
                $filename,
                (string) ($part->contentType ?: 'application/octet-stream'),
                $folder,
            );

            $integration->recordSuccess();
            $this->em->flush();

            return null;
        } catch (IntegrationException $e) {
            $integration->recordFailure($e->getMessage());
            $this->em->flush();

            return $e->getMessage();
        } catch (RuntimeException $e) {
            // AttachmentResolver throws this when a provider-hosted part cannot
            // be materialised — a different failure from the upload itself, and
            // not one that says anything about the integration's health.
            return $e->getMessage();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Scrubber buckets, but only where a date bar means anything.
     *
     * Not on an album, a person or a search result: those are already a slice of
     * the library, so a whole-library date bar beside them would be describing
     * something other than what is on screen.
     *
     * @return list<\App\Domain\DTO\Integration\TimelineBucket>
     */
    private function buckets(
        Integration $integration,
        object $driver,
        ?string $folderId,
        ?string $query,
    ): array {
        if (false === $integration->supports(Capability::Timeline) || false === $driver instanceof TimelineDriverInterface) {
            return [];
        }

        if (null !== $query || (null !== $folderId && '' !== $folderId && 'timeline' !== $folderId)) {
            return [];
        }

        try {
            return $driver->timelineBuckets($integration);
        } catch (IntegrationException) {
            // A missing scrubber is cosmetic; the listing already succeeded.
            return [];
        }
    }

    /**
     * Bucketed exactly as a local upload is, so a file pulled from a service
     * and one dragged in from disk land in the same place and are
     * indistinguishable from then on.
     */
    private function storePart(Message $message, string $filename, string $mime, string $contents): void
    {
        $storagePath = $this->attachmentStorage->store(
            (int) $message->account->id,
            (int) ($message->mailbox->id ?? 0),
            (int) $message->id,
            $filename,
            $contents,
        );

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = $mime;
        $part->filename    = basename($filename);
        $part->disposition = 'attachment';
        $part->size        = strlen($contents);
        $part->storagePath = $storagePath;
        $part->isInline    = false;

        $message->addMessagePart($part);
        $this->em->persist($part);
    }
}
