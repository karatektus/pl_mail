<?php

declare(strict_types=1);

namespace App\Jmap\Controller;

use App\Domain\Helper\UploadStorage;
use App\Entity\Contacts\Contact;
use App\Entity\Mail\Account;
use App\Entity\Mail\UploadedBlob;
use App\Entity\User\User;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Blob\BlobId;
use App\Jmap\Protocol\Exception\MethodException;
use App\Service\Contacts\ContactPhotoStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Contact photo staging and serving, beside JMAP.
 *
 * A client cannot put bytes through a JSON method call, so the photo takes
 * the same two-step path RFC 8620 §6.1 gives uploads: POST the file here,
 * get a blobId back, then reference it as "photoBlobId" in a Contact/set
 * create or update. Contact/set consumes the blob exactly once — bytes move
 * into the photo store, the staged file and its row are deleted — so a
 * replayed blobId answers notFound instead of re-attaching.
 *
 * Serving is authenticated like everything else here: a contact's photo is
 * only visible to its owner, never to the public.
 */
final class JmapContactPhotoController extends AbstractController
{
    /**
     * @var array<string,string>
     */
    private const array MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactPhotoStore $photos,
        private readonly UploadStorage $storage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/jmap/contacts/photo', name: 'jmap_contacts_photo_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        $file = $request->files->get('file');

        if (false === $file instanceof UploadedFile) {
            return $this->problem('invalidArguments', 'A multipart "file" field is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->photos->validateUpload($file);
        } catch (MethodException | \InvalidArgumentException $exception) {
            return $this->problem('invalidArguments', $exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $account = $this->resolveAccount($request, $user);

        if ($account instanceof JsonResponse) {
            return $account;
        }

        if (null === $account) {
            return $this->problem('invalidArguments', 'Photo upload needs a mail account.', Response::HTTP_BAD_REQUEST);
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $content = $file->getContent();
        $size = strlen($content);

        $path = $this->storage->store((int) $account->id, $content);

        $blob = new UploadedBlob($account, $path, $mime, $size);

        $this->entityManager->persist($blob);
        $this->entityManager->flush();

        return new JsonResponse([
            'blobId' => (string) BlobId::forUpload((int) $blob->id),
            'size' => $size,
            'type' => $mime,
        ]);
    }

    #[Route('/jmap/contacts/photo/{contactId}', name: 'jmap_contacts_photo_serve', methods: ['GET'])]
    public function serve(
        string $contactId,
        #[CurrentUser] User $user,
    ): Response {
        $contact = true === ctype_digit($contactId)
            ? $this->entityManager->find(Contact::class, (int) $contactId)
            : null;

        if (null === $contact || $contact->usr->id !== $user->id || null === $contact->photoPath || '' === $contact->photoPath) {
            throw $this->createNotFoundException();
        }

        $absolute = $this->photos->absolutePath($contact->photoPath);

        if (null === $absolute || false === is_file($absolute)) {
            throw $this->createNotFoundException();
        }

        $extension = strtolower((string) pathinfo($contact->photoPath, PATHINFO_EXTENSION));

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream');

        return $response;
    }

    /**
     * Contacts are user-scoped, so any of the user's accounts stages the
     * upload — the account is only the blob's namespacing, the same role it
     * plays in the generic upload endpoint. An explicit "accountId" form
     * field wins; otherwise the first account answers. Returns a problem
     * response instead of an account when the explicit id names nothing.
     */
    private function resolveAccount(Request $request, User $user): Account|JsonResponse|null
    {
        $accountId = $request->request->get('accountId');

        if (is_string($accountId) && '' !== $accountId) {
            try {
                return $this->accountResolver->resolve($user, $accountId);
            } catch (MethodException) {
                return $this->problem('accountNotFound', 'No such account.', Response::HTTP_NOT_FOUND);
            }
        }

        foreach ($user->accounts as $account) {
            return $account;
        }

        return null;
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
