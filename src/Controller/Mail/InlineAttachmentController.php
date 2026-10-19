<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\Helper\InlineDisposition;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\InlineAttachmentSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The one route an inline image in a message body is loaded through.
 *
 * WHY THIS ROUTE IS ANONYMOUS (no `#[IsGranted]`, PUBLIC_ACCESS in security.yaml)
 * ------------------------------------------------------------------------------
 * The body renders in a sandbox WITHOUT `allow-same-origin`, so the frame has an
 * opaque origin and the browser sends NO session cookie with its subresource
 * loads. Behind `ROLE_USER` a `/mail/attachment/{id}` image was answered with a
 * 302 to the login page, and every inline image showed as an empty box. The
 * signature is the authorization instead, as it is for the image proxy (see
 * {@see ImageProxyController}): an HMAC over the part id that only
 * {@see \App\Service\Mail\InlineAttachmentLinker} mints, for the parts of a
 * message it is rendering for someone who may read it.
 *
 * Deliberately narrower than {@see AttachmentController::serve()}: this route
 * serves a part only when {@see AttachmentResolver::inlineTypeFor()} finds a
 * raster type for it (its stated type, or its bytes when the sender labelled it
 * `octet-stream`), so an anonymous caller holding a signature can fetch an
 * image and nothing else. The signature expires (see
 * {@see InlineAttachmentSigner}). Every refusal is the same 404, so a caller
 * cannot tell a bad or expired signature from a part that does not exist or is
 * not an image.
 */
final class InlineAttachmentController extends AbstractController
{
    public function __construct(
        private readonly InlineAttachmentSigner $signer,
        private readonly AttachmentResolver $attachmentResolver,
    ) {}

    #[Route('/mail/inline/{id}', name: 'app_mail_inline_attachment', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function serve(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $signature = (string) $request->query->get('s', '');
        $expires   = $request->query->getInt('e');

        if ('' === $signature || false === $this->signer->isValid($id, $expires, $signature)) {
            throw new NotFoundHttpException();
        }

        $part = $entityManager->find(MessagePart::class, $id);

        if (false === $part instanceof MessagePart) {
            throw new NotFoundHttpException();
        }

        $absolutePath = $this->attachmentResolver->absolutePathFor($part);
        $contentType  = $this->attachmentResolver->inlineTypeFor($part, $absolutePath);

        if (null === $contentType) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', InlineDisposition::servedType($contentType));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', InlineDisposition::SANDBOX_CSP);
        // The bytes of a part never change under its id, so the browser may
        // keep them, but no longer than the link works; `private` keeps a
        // shared cache out of it.
        $response->setPrivate();
        $response->setMaxAge(min(InlineAttachmentSigner::LIFETIME, $this->signer->secondsLeft($expires)));
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            InlineDisposition::headerFilename($part->filename),
        );

        return $response;
    }
}
