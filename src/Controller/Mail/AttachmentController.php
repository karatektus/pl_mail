<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\Helper\InlineDisposition;
use App\Domain\Helper\MediaAttachment;
use App\Domain\Helper\PdfAttachment;
use App\Entity\Mail\MessagePart;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\AttachmentThumbnailer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AttachmentController extends AbstractController
{
    public function __construct(
        private readonly AttachmentResolver $attachmentResolver,
        private readonly AttachmentThumbnailer $thumbnailer,
    ) {}

    /**
     * The viewer for a PDF, image or video attachment, rendered into the modal
     * frame.
     *
     * Returns the viewer's HTML, not the document — the bytes are fetched by
     * the browser from serve() (PDF, image) or video(). For a PDF that is the
     * whole reason the feature needed no change to how attachments are
     * served: `fetch()` ignores Content-Disposition, so a PDF can stay
     * `attachment` (as InlineDisposition insists) and still be read. Widening
     * the inline allow-list to admit application/pdf would hand mail-controlled
     * bytes to the browser's own PDF plugin, which is precisely the "future
     * widening is a bug" that helper's docblock warns about. An image needs
     * nothing new either: the types MediaAttachment offers are ones serve()
     * already sends inline.
     *
     * 404 rather than a refusal for anything else: there is no viewer for it,
     * so the route does not exist for that part. The chip only renders this
     * link where attachment_preview_kind() agrees, so reaching it otherwise
     * means somebody typed the URL.
     *
     * Rendering PDFs in the browser rather than on the server is a standing
     * decision, not a shortcut — see AttachmentThumbnailer's docblock and the
     * README beside the vendored library under public/pdfjs.
     */
    #[Route('/mail/attachment/{id}/preview', name: 'app_mail_attachment_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function preview(MessagePart $part): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $part);

        // Ciphertext: there is nothing in it a viewer could show. See MessagePart::$sealed.
        if (true === $part->sealed) {
            throw $this->createNotFoundException();
        }

        if (true === PdfAttachment::matches($part->contentType, $part->filename)) {
            return $this->render('mail/_attachment_preview.html.twig', ['part' => $part]);
        }

        $kind = MediaAttachment::kindOf($part->contentType, $part->filename);

        if (null === $kind) {
            throw $this->createNotFoundException();
        }

        return $this->render('mail/_media_preview.html.twig', ['part' => $part, 'kind' => $kind]);
    }

    /**
     * A video attachment, served so a `<video>` can play it (#295).
     *
     * Its own route rather than a wider serve(): serve() labels everything off
     * the InlineDisposition list `application/octet-stream`, and a `<video>`
     * cannot be relied on to play bytes under that label — with nosniff the
     * browser has every right to believe it. Widening the list instead would widen
     * the signed `cid:` route and the sniffer with it. Here the type served is
     * always one of MediaAttachment's four, never the sender's string, and the
     * response carries the same nosniff and sandbox CSP serve() sends.
     *
     * BinaryFileResponse answers Range requests, which is what lets the player
     * seek without fetching the whole file first.
     */
    #[Route('/mail/attachment/{id}/video', name: 'app_mail_attachment_video', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function video(MessagePart $part): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $part);

        $type = true === $part->sealed ? null : MediaAttachment::videoType($part->contentType, $part->filename);

        if (null === $type) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($this->attachmentResolver->absolutePathFor($part));
        $response->headers->set('Content-Type', $type);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', InlineDisposition::SANDBOX_CSP);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            InlineDisposition::headerFilename($part->filename, 'video'),
        );

        return $response;
    }

    /**
     * A cached preview for an image attachment.
     *
     * Separate from serve() so the thumbnail can be cached hard by the browser
     * and, more importantly, so a part that turns out not to be decodable
     * answers 404 and lets the chip keep its icon, rather than sending the
     * full-size original under a preview's name.
     */
    #[Route('/mail/attachment/{id}/thumbnail', name: 'app_mail_attachment_thumbnail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function thumbnail(MessagePart $part): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $part);

        $path = $this->thumbnailer->thumbnailPath($part);

        if (null === $path) {
            throw $this->createNotFoundException('No preview for this attachment.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/webp');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preview.webp');

        // The bytes behind a part never change, so this is safe to hold on to.
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    #[Route('/mail/attachment/{id}', name: 'app_mail_attachment', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function serve(MessagePart $part, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $part);

        $absolutePath = $this->attachmentResolver->absolutePathFor($part);
        // A sealed part is an OpenPGP message, whatever the browser said the
        // file was: served as what it is, and only to be downloaded. Otherwise
        // a part labelled `application/octet-stream` or `image/octet-stream` is
        // very often an inline image whose sender did not say so; the resolver
        // lets the bytes decide for those labels only. See inlineTypeFor().
        $contentType  = true === $part->sealed
            ? 'application/octet-stream'
            : ($this->attachmentResolver->inlineTypeFor($part, $absolutePath) ?? $part->contentType ?? 'application/octet-stream');

        // An allow-list, not a prefix test. `image/svg+xml` starts with
        // "image/" and executes script when loaded as a document, and the type
        // comes from the MIME headers of an incoming mail — see
        // InlineDisposition, which carries the reasoning and the reachability.
        $inlineAllowed = InlineDisposition::allows($contentType);
        $forceDownload = true === $request->query->getBoolean('download')
            || false === $inlineAllowed;

        $response = new BinaryFileResponse($absolutePath);
        // Not $contentType: the sender chose it. See InlineDisposition::servedType().
        $response->headers->set('Content-Type', InlineDisposition::servedType($contentType));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // On every response, download or not: it costs an inline image nothing
        // and it means a future widening of the allow-list is a bug rather than
        // a vulnerability.
        $response->headers->set('Content-Security-Policy', InlineDisposition::SANDBOX_CSP);
        $response->setContentDisposition(
            true === $forceDownload
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
            InlineDisposition::headerFilename($part->filename),
        );

        return $response;
    }

}
