<?php

declare(strict_types=1);

namespace App\Controller\Notes;

use App\Entity\Note\Note;
use App\Entity\User\User;
use App\Repository\Note\NoteRepository;
use App\Service\Note\NoteMediaRefused;
use App\Service\Note\NoteMediaResponder;
use App\Service\Note\NoteMediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A note's pictures, drawings and recordings for apps: /jmap/notes/{id}/media.
 *
 * Under the JMAP firewall, so an app authenticates the way it does for
 * everything else (an app password). Uploads are the raw body, like JMAP's own
 * upload and Drive's (`?kind=image|drawing|audio&name=…`, Content-Type the
 * file's type); a download is a GET; a DELETE removes one. The note's `media`
 * list, read through Note/get, says what is there.
 */
#[Route('/jmap/notes/{id<\d+>}/media', name: 'jmap_note_media_')]
final class NoteMediaApiController extends AbstractController
{
    public function __construct(
        private readonly NoteRepository         $notes,
        private readonly NoteMediaStorage       $storage,
        private readonly NoteMediaResponder     $responder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $note = $this->owned($id, $user);
        if (null === $note) {
            return new JsonResponse(['type' => 'notFound', 'description' => 'No such Note.'], Response::HTTP_NOT_FOUND);
        }

        $temp = tempnam(sys_get_temp_dir(), 'note-media-');
        if (false === $temp) {
            throw new \RuntimeException('No temporary file.');
        }

        $in  = $request->getContent(true);
        $out = fopen($temp, 'wb');
        if (false === $out) {
            throw new \RuntimeException('No temporary file.');
        }
        stream_copy_to_stream($in, $out, NoteMediaStorage::MAX_BYTES['audio'] + 1);
        fclose($out);

        try {
            $entry = $this->storage->add(
                $note,
                (string) $request->query->get('kind', 'image'),
                (string) $request->query->get('name', ''),
                (string) $request->headers->get('Content-Type', ''),
                $temp,
            );
        } catch (NoteMediaRefused $e) {
            @unlink($temp);

            return new JsonResponse(['type' => 'invalidArguments', 'description' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return new JsonResponse($entry, Response::HTTP_CREATED);
    }

    #[Route('/{mediaId<[a-f0-9]{24}>}', name: 'show', methods: ['GET'])]
    public function show(int $id, string $mediaId, #[CurrentUser] User $user): Response
    {
        $note = $this->owned($id, $user);

        return (null !== $note ? $this->responder->respond($note, $mediaId) : null)
            ?? new JsonResponse(['type' => 'notFound', 'description' => 'No such file.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/{mediaId<[a-f0-9]{24}>}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id, string $mediaId, #[CurrentUser] User $user): JsonResponse
    {
        $note = $this->owned($id, $user);

        if (null === $note || false === $this->storage->remove($note, $mediaId)) {
            return new JsonResponse(['type' => 'notFound', 'description' => 'No such file.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function owned(int $id, User $user): ?Note
    {
        $note = $this->notes->find($id);

        return null !== $note && $note->usr->id === $user->id ? $note : null;
    }
}
