<?php

declare(strict_types=1);

namespace App\Jmap\Controller;

use App\Domain\Helper\UploadStorage;
use App\Entity\UploadedBlob;
use App\Entity\User;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Blob\BlobId;
use App\Jmap\Protocol\Exception\MethodException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Blob upload (RFC 8620 §6.1). Advertised as uploadUrl in the Session object;
 * SessionBuilder must keep the template in sync with this route.
 *
 * The client sends raw bytes with a Content-Type and gets back a blobId it can
 * reference from a later /set call. Nothing is parsed or trusted here — the
 * declared type is stored as metadata and echoed back, exactly as the spec
 * requires, but the download endpoint still refuses to render anything but
 * images inline, so a client claiming text/html cannot get it executed on our
 * origin.
 */
final class JmapUploadController extends AbstractController
{
    /** Must match maxSizeUpload in SessionBuilder::coreCapabilities(). */
    private const int MAX_SIZE = 50_000_000;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly UploadStorage $storage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/jmap/upload/{accountId}', name: 'jmap_upload', methods: ['POST'])]
    public function upload(
        string $accountId,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        try {
            $account = $this->accountResolver->resolve($user, $accountId);
        } catch (MethodException) {
            return $this->problem('accountNotFound', 'No such account.', Response::HTTP_NOT_FOUND);
        }

        $content = $request->getContent();
        $size = strlen($content);

        if (0 === $size) {
            return $this->problem('invalidArguments', 'The request body is empty.', Response::HTTP_BAD_REQUEST);
        }

        if ($size > self::MAX_SIZE) {
            return $this->problem(
                'tooLarge',
                sprintf('Uploads are limited to %d bytes.', self::MAX_SIZE),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $contentType = $this->contentTypeOf($request);
        $path = $this->storage->store((int) $account->getId(), $content);

        $blob = new UploadedBlob($account, $path, $contentType, $size);

        $this->entityManager->persist($blob);
        $this->entityManager->flush();

        return new JsonResponse([
            'accountId' => (string) $account->getId(),
            'blobId' => (string) BlobId::forUpload((int) $blob->id),
            'type' => $contentType,
            'size' => $size,
        ]);
    }

    /**
     * Strips any charset/boundary parameters and falls back to the generic
     * binary type, so the stored value is always a bare media type.
     */
    private function contentTypeOf(Request $request): string
    {
        $header = (string) $request->headers->get('Content-Type', '');
        $type = trim(explode(';', $header)[0]);

        if ('' === $type || 1 !== preg_match('#^[\w.+-]+/[\w.+-]+$#', $type)) {
            return 'application/octet-stream';
        }

        return $type;
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
