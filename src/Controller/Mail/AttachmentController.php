<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Entity\Mail\MessagePart;
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
    #[Route('/mail/attachment/{id}/thumbnail', name: 'app_mail_attachment_thumbnail', methods: ['GET'])]
    public function thumbnail(MessagePart $part): Response
    {
        $this->assertOwned($part);

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

    #[Route('/mail/attachment/{id}', name: 'app_mail_attachment', methods: ['GET'])]
    public function serve(MessagePart $part, Request $request): Response
    {
        $this->assertOwned($part);

        $absolutePath = $this->attachmentResolver->absolutePathFor($part);
        $contentType  = $part->getContentType() ?? 'application/octet-stream';

        // Only images render inline; everything else (crucially any text/html)
        // is forced to download so email-supplied markup never runs on our origin.
        $inlineAllowed = true === str_starts_with($contentType, 'image/');
        $forceDownload = true === $request->query->getBoolean('download')
            || false === $inlineAllowed;

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            true === $forceDownload
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
            $part->getFilename() ?? 'attachment',
        );

        return $response;
    }

    /**
     * Not via the mailbox: Gmail/Graph messages are label-only and have none.
     * The message itself always carries the account.
     */
    private function assertOwned(MessagePart $part): void
    {
        if ($part->getMessage()->getAccount()->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
