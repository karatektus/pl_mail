<?php

declare(strict_types=1);

namespace App\Jmap\Controller;

use App\Domain\Helper\InlineDisposition;
use App\Entity\User\User;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Blob\BlobId;
use App\Jmap\Blob\BlobResolver;
use App\Jmap\Blob\ResolvedBlob;
use App\Jmap\Protocol\Exception\MethodException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Blob download (RFC 8620 §6.2). The URL template is advertised as downloadUrl
 * in the Session object, and SessionBuilder must keep the two in sync.
 *
 * The {name} segment is client-chosen and used only for the filename; it is
 * never trusted for lookup — the blobId alone identifies the bytes, and
 * BlobResolver scopes that to the account.
 *
 * Content handling mirrors AttachmentController: nosniff always, and inline
 * rendering only for images, so email-supplied HTML can never execute on our
 * origin. That matters more here than in the web UI, because a JMAP client may
 * hand this URL straight to a webview.
 */
final class JmapDownloadController extends AbstractController
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly BlobResolver $blobResolver,
    ) {
    }

    #[Route(
        '/jmap/download/{accountId}/{blobId}/{name}',
        name: 'jmap_download',
        requirements: ['name' => '.+'],
        defaults: ['name' => 'download'],
        methods: ['GET'],
    )]
    public function download(
        string $accountId,
        string $blobId,
        string $name,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        try {
            $account = $this->accountResolver->resolve($user, $accountId);
        } catch (MethodException) {
            return $this->problem('accountNotFound', 'No such account.', Response::HTTP_NOT_FOUND);
        }

        $parsed = BlobId::parse($blobId);

        if (null === $parsed) {
            return $this->problem('blobNotFound', 'Malformed blobId.', Response::HTTP_NOT_FOUND);
        }

        $blob = $this->blobResolver->resolve($account, $parsed);

        if (null === $blob) {
            return $this->problem('blobNotFound', 'No such blob in this account.', Response::HTTP_NOT_FOUND);
        }

        // "accept" lets the client ask for a content type; honouring it blindly
        // would let a caller relabel HTML as an image, so it is ignored and the
        // stored type wins.
        return $this->serve($blob, $this->filenameFor($blob, $name));
    }

    private function serve(ResolvedBlob $blob, string $filename): Response
    {
        // Same allow-list as AttachmentController, and listed as a
        // consistency finding rather than an exploitable one: the jmap
        // firewall is stateless and wants an Authorization header a top-level
        // browser navigation does not send, so a direct hit ends at 401. That
        // is a property of a firewall documented somewhere else, which is a
        // thin thing for this to depend on.
        $inlineAllowed = InlineDisposition::allows($blob->contentType);

        $disposition = true === $inlineAllowed
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        if (true === $blob->isFile()) {
            $response = new BinaryFileResponse((string) $blob->path);
        } else {
            $response = new Response((string) $blob->content);
        }

        $response->headers->set('Content-Type', $blob->contentType);
        $response->headers->set('Content-Security-Policy', InlineDisposition::SANDBOX_CSP);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($response instanceof BinaryFileResponse) {
            $response->setContentDisposition($disposition, $filename);

            return $response;
        }

        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition($disposition, $filename),
        );

        return $response;
    }

    /**
     * Prefer the client's {name}, but never let it escape the header — a
     * newline or a path separator in a Content-Disposition is a header
     * injection. Symfony's makeDisposition rejects the worst of it; stripping
     * the path here keeps the fallback sane too.
     */
    private function filenameFor(ResolvedBlob $blob, string $name): string
    {
        $candidate = basename(str_replace(['\\', "\r", "\n"], '', $name));

        if ('' === $candidate || 'download' === $candidate) {
            return $blob->filename;
        }

        return $candidate;
    }

    private function problem(string $type, string $detail, int $status): JsonResponse
    {
        $response = new JsonResponse(
            [
                'type' => sprintf('urn:ietf:params:jmap:error:%s', $type),
                'status' => $status,
                'detail' => $detail,
            ],
            $status,
        );
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
