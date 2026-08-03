<?php

declare(strict_types=1);

namespace App\Controller\Integration;

use App\Controller\Mail\ComposeController;
use App\Domain\Enum\Integration\Capability;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Integration\IntegrationFilePicker;
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
 * What each endpoint asks the service for lives in IntegrationFilePicker; what
 * is here is the shape of the answer.
 *
 * Bytes are fetched eagerly rather than deferred behind an integration://
 * storage scheme. The cap bounds the request, and it keeps AttachmentResolver
 * — which every send and every download already goes through — untouched.
 *
 * The attach response is JSON rather than a Turbo Stream: the compose window is
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
        private readonly IntegrationFilePicker  $picker,
        private readonly EntityManagerInterface $em,
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

        $view = $this->picker->browse($integration, $folderId, $cursor, $query);

        return $this->render('integration/_picker.html.twig', [
            'integration' => $integration,
            'listing'     => $view->listing,
            'error'       => $view->error,
            'folderId'    => $folderId,
            'draftId'     => $draftId,
            'query'       => $query,
            'maxBytes'    => ComposeController::MAX_ATTACHMENT_BYTES,
            'canLink'     => $integration->supports(Capability::ShareLink),
            'canThumb'    => $integration->supports(Capability::Thumbnail),
            'canSearch'   => $view->canSearch,
            'buckets'     => $view->buckets,
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

        $preview = $this->picker->preview($integration, $fileId);

        // A placeholder rather than a 404. Plenty of things legitimately have no
        // preview — a zip, a person Immich never generated a face crop for — and
        // answering 404 for each one filled the browser console with failed
        // requests that looked like breakage and buried real errors.
        [$body, $mime] = null === $preview
            ? [$this->placeholderSvg($fileId), 'image/svg+xml']
            : [$preview->contents, $preview->mime];

        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * A neutral stand-in for a preview that does not exist.
     *
     * Inline SVG so it costs no asset pipeline and no extra request, and it
     * carries the same glyph the row would have fallen back to anyway. The
     * person variant is a bust rather than a sheet of paper, because a nameless
     * circle with a document in it reads as an error.
     */
    private function placeholderSvg(string $fileId): string
    {
        $glyph = true === str_starts_with($fileId, 'person:')
            // Head and shoulders.
            ? '<circle cx="32" cy="25" r="10"/><path d="M14 54c0-9.9 8.1-18 18-18s18 8.1 18 18z"/>'
            // Sheet with a folded corner.
            : '<path d="M20 12h16l10 10v30H20z" opacity=".55"/><path d="M36 12v10h10z"/>';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
            .'<rect width="64" height="64" fill="rgb(113 113 122 / .12)"/>'
            .'<g fill="rgb(113 113 122 / .55)">%s</g></svg>',
            $glyph,
        );
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

        /** @var array<string,string> $selection fileId => 'copy'|'link' */
        $selection = $request->request->all('mode');

        $transfer = $this->picker->pullIntoDraft(
            $integration,
            $message,
            $selection,
            ComposeController::MAX_ATTACHMENT_BYTES,
        );

        return new JsonResponse([
            'attachmentsHtml' => $this->renderView('compose/_attachments.html.twig', ['message' => $message]),
            'links'           => $transfer->links,
            'errors'          => $transfer->errors,
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

        if (false === $this->isCsrfTokenValid('integration-save-'.$part->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Same ownership rule AttachmentController uses for downloads: the
        // part belongs to a message on one of this user's accounts.
        if ($part->message?->account?->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $error = $this->picker->pushAttachment(
            $integration,
            $part,
            $integration->getSetting(self::UPLOAD_FOLDER_SETTING),
        );

        return null === $error
            ? $this->toast('integration.saved_to', ['%name%' => $integration->name], false)
            : $this->toast('integration.save_failed', ['%reason%' => $error], true);
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

        if ($message->account?->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (false === $message->isDraft() || null !== $message->sentAt) {
            throw $this->createAccessDeniedException('Only unsent drafts can be edited.');
        }

        return $message;
    }

    /** A query-string field that was left empty is the same as one absent. */
    private function blankToNull(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Ownership, activity and capability in one place — a paused connection or
     * one whose provider cannot do the thing is refused here rather than
     * merely omitted from a menu.
     */
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
