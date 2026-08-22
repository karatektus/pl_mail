<?php

declare(strict_types=1);

namespace App\Controller\Integration;

use App\Domain\Helper\InlineDisposition;
use App\Controller\Mail\ComposeAttachmentController;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Security\Voter\OwnershipVoter;
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

    /**
     * Integration::$settings key remembering the folder or album the user last
     * chose in the destination picker, so the next save opens where the last
     * one landed. Kept apart from UPLOAD_FOLDER_SETTING on purpose: that one is
     * the fire-and-forget default a filter rule saves to, and a manual save to
     * one folder must not quietly redirect a rule to another. A per-save choice
     * always wins over both — this only decides where the picker starts.
     */
    public const string LAST_DESTINATION_SETTING = 'save.last_destination';

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
        // The same route opens two ways. Attaching a file INTO a draft is the
        // default; picking a destination to save an attachment OUT to is the
        // other, and it renders a folder/album chooser rather than a selectable
        // file list. The mode decides, so one modal shell and one JS controller
        // serve both.
        if ('destination' === $request->query->get('mode')) {
            return $this->browseDestination($integration, $request);
        }

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
            'maxBytes'    => ComposeAttachmentController::MAX_ATTACHMENT_BYTES,
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
        // A preview is always an image, so anything the provider answers that
        // is not one is treated as no preview at all rather than passed on.
        //
        // The type here comes from Nextcloud, Immich, Dropbox, OneDrive or
        // Drive, and this response carried no Content-Disposition — so it was
        // implicitly inline, in the app's own origin, with a type chosen
        // elsewhere. `text/html` or `image/svg+xml` coming back from a file an
        // attacker dropped in a shared folder would have rendered here. That is
        // a realistic shape for exactly the setups these integrations exist
        // for.
        //
        // Falling back to the placeholder rather than to a download, because a
        // download prompt fired by a thumbnail loading is worse than a grey
        // box, and the placeholder is built by placeholderSvg() below — our own
        // bytes, no remote input in them.
        $usable = null !== $preview && InlineDisposition::allows($preview->mime);

        [$body, $mime] = false === $usable
            ? [$this->placeholderSvg($fileId), 'image/svg+xml']
            : [$preview->contents, $preview->mime];

        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
            // The placeholder is an SVG and has to stay inline to render in an
            // <img>, so the response carries the sandbox instead: no scripts,
            // no forms, opaque origin, even on a direct navigation.
            'Content-Security-Policy' => InlineDisposition::SANDBOX_CSP,
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
            ComposeAttachmentController::MAX_ATTACHMENT_BYTES,
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
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $part);

        // An explicit destination from the picker wins; its absence is the
        // fire-and-forget path — save straight to the configured default, which
        // is what a filter rule and a save with no picker both do. The picker
        // sends the field even for the root ('' is "save at the top level"), so
        // presence, not emptiness, is what tells a choice from a default.
        $userChosen = $request->request->has('destination');
        $destination = $userChosen
            ? (string) $request->request->get('destination')
            : $integration->getSetting(self::UPLOAD_FOLDER_SETTING);

        // A chosen destination is attacker-controllable, so the picker's saves
        // are validated against this account; the trusted default is not.
        $error = $this->picker->pushAttachment($integration, $part, $destination, $userChosen);

        if (null === $error && true === $userChosen) {
            // Remember it, so the next save opens where this one landed. Its own
            // key, not the rule default — see LAST_DESTINATION_SETTING.
            $integration->setSetting(self::LAST_DESTINATION_SETTING, $destination);
            $this->em->flush();
        }

        return null === $error
            ? $this->toast('integration.saved_to', ['%name%' => $integration->name], false)
            : $this->toast('integration.save_failed', ['%reason%' => $error], true);
    }

    /**
     * Create a folder or album from the destination picker, then re-render it
     * sitting in the new container so the next click is "Save here".
     *
     * A folder store nests the new folder under wherever the user is browsing;
     * a photo library's albums are flat, so the parent is ignored and the
     * chooser simply re-lists with the new album present.
     */
    #[Route('/{id}/create-destination/{part}', name: 'create_destination', methods: ['POST'])]
    public function createDestination(Integration $integration, MessagePart $part, Request $request): Response
    {
        $this->assertUsable($integration, Capability::Upload);

        if (false === $this->isCsrfTokenValid('integration-destination-'.$part->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $part);

        $parent = $this->blankToNull($request->request->get('parent'));
        $name = trim((string) $request->request->get('name'));
        $landAt = $parent;
        $createError = null;

        if ('' !== $name) {
            try {
                $landAt = $this->picker->createDestination($integration, $parent, $name);
            } catch (IntegrationException $e) {
                $createError = $e->getMessage();
            }
        }

        return $this->renderDestination($integration, $part, $landAt, $createError);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The destination chooser for one attachment: a folder tree to save into,
     * or a photo library's albums to file into.
     */
    private function browseDestination(Integration $integration, Request $request): Response
    {
        // Upload is the capability a destination pick actually needs; every
        // upload-capable file provider can also browse, so listing follows.
        $this->assertUsable($integration, Capability::Upload);

        $part = $this->ownedPart($request->query->getInt('part'));
        $folderId = $this->blankToNull($request->query->get('folder'));

        return $this->renderDestination($integration, $part, $folderId, null);
    }

    /**
     * Render the destination modal frame at a folder/album, optionally carrying
     * a message from a create attempt that failed.
     */
    private function renderDestination(
        Integration $integration,
        MessagePart $part,
        ?string $folderId,
        ?string $createError,
    ): Response {
        $view = $this->picker->destinations($integration, $folderId, null);

        return $this->render('integration/_destination.html.twig', [
            'integration' => $integration,
            'part'        => $part,
            'listing'     => $view->listing,
            'error'       => $view->error,
            'createError' => $createError,
            'folderId'    => $folderId,
            'canCreate'   => $this->picker->canCreateDestination($integration),
        ]);
    }

    /**
     * A part the current user owns, by the same rule saveAttachment uses. A
     * shared helper so the destination browse and the save cannot drift on who
     * is allowed to reach an attachment.
     */
    private function ownedPart(int $id): MessagePart
    {
        $part = 0 === $id ? null : $this->em->find(MessagePart::class, $id);

        if (null === $part) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $part);

        return $part;
    }

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

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

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
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $integration);

        if (false === $integration->supports($capability)) {
            throw $this->createNotFoundException();
        }
    }
}
