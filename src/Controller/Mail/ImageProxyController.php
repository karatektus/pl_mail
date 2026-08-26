<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Service\Mail\ImageProxyFetcher;
use App\Service\Mail\ImageProxySigner;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The one route a remote image in an email may be loaded through.
 *
 * Everything interesting is in {@see ImageProxyFetcher}; this is the seam
 * between it and HTTP. The two jobs here are checking the signature and making
 * sure that nothing which comes back from a stranger's server is ever served in
 * a way a browser might treat as anything but a picture.
 *
 * WHY THIS ROUTE IS ANONYMOUS (no `#[IsGranted]`, PUBLIC_ACCESS in security.yaml)
 * ------------------------------------------------------------------------------
 * The mail body renders in a sandbox WITHOUT `allow-same-origin`, so its origin
 * is opaque — the deliberate XSS containment that means a sanitizer gap cannot
 * reach the app origin or its cookies. The price of an opaque origin is that the
 * browser sends NO session cookie with the frame's subresource loads, so an
 * `<img>` pointing here arrives unauthenticated. Behind ROLE_USER those requests
 * were answered with the login page (HTTP 200, text/html), which an `<img>`
 * renders as a broken icon — every remote image in the client was broken.
 *
 * The signature is the authorization. It is `hash_hmac('sha256', url, secret)`
 * with the deployment's own secret and NO user in the payload (see
 * {@see ImageProxySigner}) — a global capability token that only OUR rewriting
 * pipeline can mint, so only URLs we signed are fetchable. This is the standard
 * signed-image-proxy design (cf. GitHub's Camo). Session identity adds nothing
 * a per-user check could enforce, because the token is not per user; requiring
 * it only broke the frame. Every SSRF rule still lives in {@see ImageProxyFetcher}
 * and runs on every fetch regardless of who (if anyone) is signed in.
 */
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
        private readonly LoggerInterface   $logger,
    ) {}

    #[Route('/mail/image-proxy', name: 'app_mail_image_proxy', methods: ['GET'])]
    public function proxy(Request $request): Response
    {
        $url       = (string) $request->query->get('u', '');
        $signature = (string) $request->query->get('s', '');

        if ('' === $url || false === $this->signer->isValid($url, $signature)) {
            // Without this line the refusal is completely invisible. The reader
            // gets the same 1x1 GIF as every other failure (deliberately, see
            // above), no fetch is attempted, so ImageProxyFetcher never logs
            // either -- and the only symptom is one picture in one mail that
            // quietly does not appear, with nothing anywhere to say why.
            $this->logger->info('ImageProxyController: refused an unsigned or badly signed image', [
                'url'          => $url,
                'hasSignature' => '' !== $signature,
            ]);

            return $this->placeholder();
        }

        $image = $this->fetcher->fetch($url);

        if (null === $image) {
            // The fetcher logs the reason it gave up; this is the line that ties
            // that reason to the picture the reader did not get.
            $this->logger->info('ImageProxyController: no image to serve', ['url' => $url]);

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
