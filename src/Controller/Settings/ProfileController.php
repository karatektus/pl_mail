<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Helper\AvatarStorage;
use App\Domain\Helper\SignatureStorage;
use App\Entity\User\User;
use App\Form\User\ProfileType;
use App\Service\User\ProfileSectionViewData;
use App\Service\User\ProfileUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Name, avatar and a saved handwritten signature, outside the setup wizard.
 *
 * The wizard's profile step is a second view onto this, not the other way
 * round: a setting reachable only from a wizard that opens once is a setting
 * nobody can change.
 */
#[Route('/settings/profile', name: 'app_settings_profile_')]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    use ChecksCsrf;

    #[Route('', name: 'save', methods: ['POST'])]
    public function save(Request $request, #[CurrentUser] User $user, ProfileUpdater $updater, ProfileSectionViewData $section): Response
    {
        // The avatar fields exist only on a form built with the same
        // avatar_source the picker rendered with — rebuilt without it, a
        // clicked thumbnail's avatarFileId is an "extra field" and the whole
        // submission fails. The value is only a hint for form shape: which
        // integration may actually be used is checked by ProfileUpdater
        // against the user's own connections.
        $source = trim((string) ($request->request->all('profile')['avatarIntegrationId'] ?? ''));

        $form = $this->createForm(ProfileType::class, $user, [
            'action'        => $this->generateUrl('app_settings_profile_save'),
            'avatar_source' => '' === $source ? null : $source,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $updater->apply($user, $form);

            return $this->redirectToRoute('app_settings_index', ['section' => 'profile']);
        }

        // The full section, not just the form: the partial renders the avatar
        // picker too, and rendering it with only profileForm crashed on the
        // missing picker variables.
        return $this->render('settings/_profile.html.twig', $section->build($user, $request, $form), new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Serves the avatar. Scoped to the signed-in user's own file — the
     * filename is random, but a route that will hand back any path under the
     * avatars directory is not something to leave lying around.
     */
    #[Route('/avatar/{filename}', name: 'avatar', requirements: ['filename' => '[0-9a-f]+\.[a-z0-9]{2,5}'], methods: ['GET'])]
    public function avatar(string $filename, #[CurrentUser] User $user, AvatarStorage $avatars): Response
    {
        if ($user->avatar !== $filename) {
            throw $this->createNotFoundException();
        }

        $path = $avatars->pathFor((string) $user->id, $filename);

        if (false === is_file($path)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path, headers: ['Cache-Control' => 'private, max-age=604800']);
    }

    /**
     * Save a handwritten signature, drawn in Settings or uploaded.
     *
     * A picture of a name, kept so it does not have to be drawn again on every
     * document. It is not a credential and there is nothing to verify against
     * it — App\Domain\Helper\SignatureStorage says so at length, and the
     * interface is careful about the same distinction.
     *
     * PNG only, established from the BYTES. A signature is stamped onto
     * somebody else's document and has to have a transparent background;
     * accepting a JPEG would be accepting a white card over their text rather
     * than supporting a second format.
     */
    #[Route('/signature', name: 'signature_save', methods: ['POST'])]
    public function saveSignature(
        Request $request,
        #[CurrentUser] User $user,
        SignatureStorage $signatures,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->assertCsrf($request, 'profile_signature');

        $file = $request->files->get('signature');

        if (false === $file instanceof UploadedFile || false === $file->isValid()) {
            throw new BadRequestHttpException('No signature.');
        }

        if (false === $signatures->isAcceptable((string) $file->getMimeType(), (int) $file->getSize())) {
            throw new BadRequestHttpException('Not an acceptable signature image.');
        }

        $user->signature = $signatures->store(
            (string) $user->id,
            (string) file_get_contents($file->getPathname()),
        );

        $entityManager->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'profile']);
    }

    /** Forget it, and delete the file rather than only the pointer. */
    #[Route('/signature/delete', name: 'signature_delete', methods: ['POST'])]
    public function deleteSignature(
        Request $request,
        #[CurrentUser] User $user,
        SignatureStorage $signatures,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->assertCsrf($request, 'profile_signature');

        $signatures->deleteAllFor((string) $user->id);
        $user->signature = null;

        $entityManager->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'profile']);
    }

    /**
     * Serves the saved signature, to the user it belongs to and nobody else.
     *
     * Same shape as avatar() above and for the same reason: the filename is
     * random, but a route that hands back any path under the directory is not
     * something to leave lying around.
     */
    #[Route('/signature/{filename}', name: 'signature', requirements: ['filename' => '[0-9a-f]+\.png'], methods: ['GET'])]
    public function signature(string $filename, #[CurrentUser] User $user, SignatureStorage $signatures): Response
    {
        if ($user->signature !== $filename) {
            throw $this->createNotFoundException();
        }

        $path = $signatures->pathFor((string) $user->id, $filename);

        if (false === is_file($path)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path, headers: ['Cache-Control' => 'private, max-age=604800']);
    }
}
