<?php

declare(strict_types=1);

namespace App\Controller\Integration;

use App\Controller\ComposeController;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Exception\IntegrationException;
use App\Domain\DTO\Integration\TimelineBucket;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Interface\TimelineDriverInterface;
use App\Entity\Integration;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Repository\IntegrationRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use App\Service\Mail\AttachmentResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Browsing a connected service from the compose window, and pulling files out
 * of it into a draft.
 *
 * Two ways to attach, chosen per file:
 *
 *   copy — the bytes are fetched and become a real MessagePart, so the file
 *   travels with the mail and the recipient needs nothing from the service.
 *   Capped at ComposeController::MAX_ATTACHMENT_BYTES, the same limit a local
 *   upload obeys.
 *
 *   link — the driver mints a public URL and the picker hands it back for the
 *   body. The only option above the cap, and unavailable on services that
 *   cannot share a single file (Immich), where an oversized photo therefore
 *   cannot be attached at all. That is the honest outcome; a link that goes
 *   nowhere would be worse.
 *
 * Bytes are fetched eagerly rather than deferred behind an integration://
 * storage scheme. The cap bounds the request, and it keeps AttachmentResolver
 * — which every send and every download already goes through — untouched.
 *
 * The response is JSON rather than a Turbo Stream: the compose window is
 * itself inside a Turbo Frame and the picker renders in the modal frame, so a
 * stream would have to reach across two nested frames and fight the modal's
 * close-on-submit. A JSON payload plus one event is less machinery.
 */
#[Route('/integrations', name: 'app_integration_')]
#[IsGranted('IS_AUTHENTICATED')]
final class FilePickerController extends AbstractController
{
    /**
     * Integration::$settings key naming the folder or album uploads land in.
     * Absent means the service's own default — the files root, or no album.
     */
    public const string UPLOAD_FOLDER_SETTING = 'upload.folder';

    public function __construct(
        private readonly IntegrationRepository     $integrationRepository,
        private readonly IntegrationDriverRegistry $drivers,
        private readonly AttachmentStorageHelper   $attachmentStorage,
        private readonly AttachmentResolver        $attachmentResolver,
        private readonly EntityManagerInterface    $em,
    ) {
    }

    /**
     * One folder's contents, rendered into the modal frame. Navigation is
     * plain links inside the frame, so there is no client-side routing and the
     * back button behaves.
     */
    #[Route('/{id}/browse', name: 'browse', methods: ['GET'])]
    public function browse(Integration $integration, Request $request): Response
    {
        $this->assertUsable($integration, Capability::Browse);

        $folderId = $request->query->get('folder');
        $draftId = $request->query->getInt('draft');
        $cursor = $this->blankToNull($request->query->get('cursor'));
        $query = $this->blankToNull($request->query->get('q'));

        $driver = $this->drivers->forIntegration($integration);
        $canSearch = $integration->supports(Capability::Search) && $driver instanceof SearchableDriverInterface;

        try {
            // Search is handed the folder it was launched from, because in some
            // views it means something else — Immich's people view filters faces
            // by name rather than searching photos.
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

        return $this->render('integration/_picker.html.twig', [
            'integration' => $integration,
            'listing'     => $listing,
            'error'       => $error,
            'folderId'    => $folderId,
            'draftId'     => $draftId,
            'query'       => $query,
            'maxBytes'    => ComposeController::MAX_ATTACHMENT_BYTES,
            'canLink'     => $integration->supports(Capability::ShareLink),
            'canThumb'    => $integration->supports(Capability::Thumbnail),
            'canSearch'   => $canSearch,
            'buckets'     => $this->buckets($integration, $driver, $folderId, $query),
        ]);
    }

    /**
     * A preview image, proxied.
     *
     * Services put previews behind the same credential as the originals, so
     * the browser cannot fetch one directly without us leaking the credential
     * into markup. Cached privately: the bytes belong to one user.
     */
    #[Route('/{id}/thumbnail', name: 'thumbnail', methods: ['GET'])]
    public function thumbnail(Integration $integration, Request $request): Response
    {
        $this->assertUsable($integration, Capability::Thumbnail);

        $fileId = (string) $request->query->get('file', '');

        if ('' === $fileId) {
            throw $this->createNotFoundException();
        }

        try {
            $preview = $this->drivers->forIntegration($integration)->thumbnail($integration, $fileId);
        } catch (IntegrationException) {
            $preview = null;
        }

        if (null === $preview) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $response = new Response($preview->contents, Response::HTTP_OK, [
            'Content-Type'           => $preview->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * Pull the selected files into the draft.
     *
     * @return JsonResponse {attachmentsHtml, links:[{name,url}], errors:[string]}
     */
    #[Route('/{id}/attach', name: 'attach', methods: ['POST'])]
    public function attach(Integration $integration, Request $request): JsonResponse
    {
        $this->assertUsable($integration, Capability::Download);

        if (false === $this->isCsrfTokenValid('integration-picker', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $message = $this->draft($request->request->getInt('draft'));
        $driver = $this->drivers->forIntegration($integration);

        /** @var list<array{name:string,url:string}> $links */
        $links = [];
        /** @var list<string> $errors */
        $errors = [];
        $attached = 0;

        /** @var array<string,string> $selection fileId => 'copy'|'link' */
        $selection = $request->request->all('mode');

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

                if ($file->size() > ComposeController::MAX_ATTACHMENT_BYTES) {
                    $errors[] = $file->filename;

                    continue;
                }

                $this->storePart($message, $file->filename, $file->mime, $file->contents);
                ++$attached;
            } catch (IntegrationException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($attached > 0) {
            // hasAttachments drives the paperclip in the message list and the
            // thread header; a part added without it leaves the draft claiming
            // it has none.
            $message->setHasAttachments(true);
            $this->em->flush();
        }

        return new JsonResponse([
            'attachmentsHtml' => $this->renderView('compose/_attachments.html.twig', ['message' => $message]),
            'links'           => $links,
            'errors'          => $errors,
        ]);
    }

    /**
     * Send an attachment the other way: out of a message and into a service.
     *
     * AttachmentResolver is what makes this work for provider-hosted mail —
     * a gmail:// or msgraph:// part is materialised on first access, so a
     * Gmail attachment that has never touched our disk uploads exactly like
     * a locally stored one.
     */
    #[Route('/{id}/save-attachment/{part}', name: 'save_attachment', methods: ['POST'])]
    public function saveAttachment(Integration $integration, MessagePart $part, Request $request): Response
    {
        $this->assertUsable($integration, Capability::Upload);

        if (false === $this->isCsrfTokenValid('integration-save-'.$part->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Same ownership rule AttachmentController uses for downloads: the
        // part belongs to a message on one of this user's accounts.
        if ($part->getMessage()?->getAccount()?->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $filename = (string) ($part->getFilename() ?: 'attachment');

        try {
            $this->drivers->forIntegration($integration)->upload(
                $integration,
                $this->attachmentResolver->absolutePathFor($part),
                $filename,
                (string) ($part->getContentType() ?: 'application/octet-stream'),
                $integration->getSetting(self::UPLOAD_FOLDER_SETTING),
            );

            $integration->recordSuccess();
            $this->em->flush();

            return $this->toast('integration.saved_to', ['%name%' => $integration->name], false);
        } catch (IntegrationException $e) {
            $integration->recordFailure($e->getMessage());
            $this->em->flush();

            return $this->toast('integration.save_failed', ['%reason%' => $e->getMessage()], true);
        } catch (\RuntimeException $e) {
            // AttachmentResolver throws this when a provider-hosted part cannot
            // be materialised — a different failure from the upload itself, and
            // not one that says anything about the integration's health.
            return $this->toast('integration.save_failed', ['%reason%' => $e->getMessage()], true);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $params
     */
    private function toast(string $message, array $params, bool $isError): Response
    {
        return $this->render('integration/_toast.stream.html.twig', [
            'toastMessage' => $message,
            'toastParams'  => $params,
            'isError'      => $isError,
        ], new Response(null, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    private function storePart(Message $message, string $filename, string $mime, string $contents): void
    {
        // Bucketed exactly as ComposeController::addAttachments does it, so a
        // file pulled from a service and one uploaded from disk land in the
        // same place and are indistinguishable from then on.
        $storagePath = $this->attachmentStorage->store(
            (int) $message->getAccount()?->getId(),
            (int) ($message->getMailbox()?->getId() ?? 0),
            (int) $message->getId(),
            $filename,
            $contents,
        );

        $part = new MessagePart()
            ->setMessage($message)
            ->setContentType($mime)
            ->setFilename(basename($filename))
            ->setDisposition('attachment')
            ->setSize(strlen($contents))
            ->setStoragePath($storagePath)
            ->setIsInline(false);

        $message->addMessagePart($part);
        $this->em->persist($part);
    }

    /**
     * The draft being composed. Same rule as every compose endpoint: it must
     * be the user's, and it must still be an unsent draft.
     */
    private function draft(int $id): Message
    {
        $message = 0 === $id ? null : $this->em->find(Message::class, $id);

        if (null === $message) {
            throw $this->createNotFoundException();
        }

        if ($message->getAccount()?->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (false === $message->isDraft() || null !== $message->getSentAt()) {
            throw $this->createAccessDeniedException('Only unsent drafts can be edited.');
        }

        return $message;
    }

    /**
     * Ownership, activity and capability in one place — a paused connection or
     * one whose provider cannot do the thing is refused here rather than
     * merely omitted from a menu.
     */
    /**
     * Scrubber buckets, but only where a date bar means anything.
     *
     * Not on an album, a person or a search result: those are already a slice of
     * the library, so a whole-library date bar beside them would be describing
     * something other than what is on screen.
     *
     * @return list<TimelineBucket>
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

    private function blankToNull(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private function assertUsable(Integration $integration, Capability $capability): void
    {
        if ($integration->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (false === $integration->supports($capability)) {
            throw $this->createNotFoundException();
        }
    }
}
