<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\Helper\InlineDisposition;
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
        $contentType  = $part->contentType ?? 'application/octet-stream';

        // An allow-list, not a prefix test. `image/svg+xml` starts with
        // "image/" and executes script when loaded as a document, and the type
        // comes from the MIME headers of an incoming mail — see
        // InlineDisposition, which carries the reasoning and the reachability.
        $inlineAllowed = InlineDisposition::allows($contentType);
        $forceDownload = true === $request->query->getBoolean('download')
            || false === $inlineAllowed;

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // On every response, download or not: it costs an inline image nothing
        // and it means a future widening of the allow-list is a bug rather than
        // a vulnerability.
        $response->headers->set('Content-Security-Policy', InlineDisposition::SANDBOX_CSP);
        $response->setContentDisposition(
            true === $forceDownload
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
            $part->filename ?? 'attachment',
        );

        return $response;
    }

}
