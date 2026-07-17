<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MessagePart;
use App\Service\Mail\AttachmentResolver;
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
    ) {}

    #[Route('/mail/attachment/{id}', name: 'app_mail_attachment', methods: ['GET'])]
    public function serve(MessagePart $part, Request $request): Response
    {
        $account = $part->getMessage()->getMailbox()->getAccount();

        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

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
}
