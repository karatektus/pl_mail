<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Service\Mail\ImageProxyFetcher;
use App\Service\Mail\ImageProxySigner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The one route a remote image in an email may be loaded through.
 *
 * Everything interesting is in {@see ImageProxyFetcher}; this is the seam
 * between it and HTTP. The two jobs here are checking the signature and making
 * sure that nothing which comes back from a stranger's server is ever served in
 * a way a browser might treat as anything but a picture.
 */
#[IsGranted('ROLE_USER')]
final class ImageProxyController extends AbstractController
{
    /**
     * A 1×1 transparent GIF, served when the fetch fails for any reason.
     *
     * Deliberately a 200 with a picture rather than a 404. The failure reason
     * is not the reader's business and, more to the point, an error that
     * distinguishes "refused" from "timed out" hands back exactly the port-scan
     * result the fetcher's rules exist to deny.
     */
    private const string PLACEHOLDER_GIF =
        'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private readonly ImageProxySigner  $signer,
        private readonly ImageProxyFetcher $fetcher,
    ) {}

    #[Route('/mail/image-proxy', name: 'app_mail_image_proxy', methods: ['GET'])]
    public function proxy(Request $request): Response
    {
        $url       = (string) $request->query->get('u', '');
        $signature = (string) $request->query->get('s', '');

        if ('' === $url || false === $this->signer->isValid($url, $signature)) {
            return $this->placeholder();
        }

        $image = $this->fetcher->fetch($url);

        if (null === $image) {
            return $this->placeholder();
        }

        $response = new BinaryFileResponse($image['path']);

        // nosniff plus an inline disposition plus a content type we checked
        // ourselves. A sender who returns HTML with an image/png header gets
        // it rendered as a broken image, not as a document on our origin.
        $response->headers->set('Content-Type', $image['contentType']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'image');

        // Private: the bytes are keyed by a URL that appeared in this user's
        // mail, and a shared cache in front of the app must not hand them to
        // anyone else.
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    private function placeholder(): Response
    {
        $response = new Response(
            (string) base64_decode(self::PLACEHOLDER_GIF, true),
            Response::HTTP_OK,
            [
                'Content-Type'           => 'image/gif',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'private, no-store',
            ],
        );

        return $response;
    }
}
