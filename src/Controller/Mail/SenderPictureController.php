<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\Enum\Mail\SenderPictureStatus;
use App\Domain\Helper\AvatarStorage;
use App\Domain\Helper\SenderPictureReference;
use App\Entity\User\User;
use App\Repository\Contacts\Contact\ContactRepository;
use App\Repository\Mail\SenderPicture\SenderPictureRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\Mail\SenderPicture\SenderPictureStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves the picture a sender wears in the list and the reading pane (#639).
 *
 * **Two routes, one action.** `/sender-picture/…` is behind the web session;
 * `/jmap/sender-picture/…` is behind the JMAP firewall, so the apps fetch the
 * same file with their own token. ContactController's photo learned why both
 * are needed: a page that loads an image from the stateless JMAP route gets a
 * Basic challenge instead, and a browser pops up a sign-in box.
 *
 * **Who may see what.** Another user's profile picture: anybody signed in to
 * this server — it is the picture that user shows on their own mail, and the
 * reference is only handed out to readers who received mail from them. A
 * contact's photo: its owner only, checked in the same query that finds it.
 * A looked-up picture: anybody signed in. It is public data that the server
 * fetched from the public internet, and is shared by every user (SenderPicture).
 *
 * **Cached as immutable** for a year: every reference carries a version of the
 * file it names (SenderPictureReference) and a stale version is a 404, so the
 * URL can never come to mean a different picture. The session listener's
 * no-cache override is switched off for the reason ProfileController::avatar()
 * records.
 *
 * **An SVG is served under a policy that runs nothing.** It was sanitised
 * before it was stored; the policy is the second lock, for whoever opens the
 * URL on its own rather than as an image.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class SenderPictureController extends AbstractController
{
    public function __construct(
        private readonly UserRepository          $users,
        private readonly AvatarStorage           $avatars,
        private readonly ContactRepository       $contacts,
        private readonly ContactPhotoStore       $photos,
        private readonly SenderPictureRepository $pictures,
        private readonly SenderPictureStore      $store,
    ) {}

    #[Route('/sender-picture/{reference}', name: 'app_sender_picture', requirements: ['reference' => SenderPictureReference::PATTERN], methods: ['GET'])]
    #[Route('/jmap/sender-picture/{reference}', name: 'app_jmap_sender_picture', requirements: ['reference' => SenderPictureReference::PATTERN], methods: ['GET'])]
    public function show(Request $request, string $reference, #[CurrentUser] User $viewer): Response
    {
        $parsed = SenderPictureReference::parse($reference) ?? throw $this->createNotFoundException();

        $path = match ($parsed->type) {
            SenderPictureReference::USER    => $this->profilePicture($parsed),
            SenderPictureReference::CONTACT => $this->contactPhoto($parsed, $viewer),
            SenderPictureReference::STORED  => $this->storedPicture($parsed),
            default                         => null,
        };

        if (null === $path || false === is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', self::typeOf($path));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if (true === str_ends_with($path, '.svg')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        $response->setPrivate();
        $response->setMaxAge(31_536_000);
        $response->setImmutable();
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->isNotModified($request);

        return $response;
    }

    private function profilePicture(SenderPictureReference $reference): ?string
    {
        $user = $this->users->find($reference->id);

        if (null === $user || null === $user->avatar || false === $reference->names($user->avatar)) {
            return null;
        }

        if (false === is_file($this->avatars->pathFor((string) $user->id, $user->avatar))) {
            return null;
        }

        return $this->avatars->thumbnailFor((string) $user->id, $user->avatar);
    }

    private function contactPhoto(SenderPictureReference $reference, User $viewer): ?string
    {
        $contact = $this->contacts->findOneBy(['id' => $reference->id, 'usr' => $viewer, 'trashedAt' => null]);

        if (null === $contact?->photoPath || false === $reference->names($contact->photoPath)) {
            return null;
        }

        return $this->photos->absolutePath($contact->photoPath);
    }

    private function storedPicture(SenderPictureReference $reference): ?string
    {
        $picture = $this->pictures->find($reference->id);

        if (null === $picture?->file || SenderPictureStatus::Found !== $picture->status || false === $reference->names($picture->file)) {
            return null;
        }

        return $this->store->path($picture->file);
    }

    private static function typeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg'         => 'image/svg+xml',
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'application/octet-stream',
        };
    }
}
