<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Helper\AvatarStorage;
use App\Entity\User\User;
use App\Form\User\ProfileType;
use App\Service\User\ProfileUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Name and avatar, outside the setup wizard.
 *
 * The wizard's profile step is a second view onto this, not the other way
 * round: a setting reachable only from a wizard that opens once is a setting
 * nobody can change.
 */
#[Route('/settings/profile', name: 'app_settings_profile_')]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    #[Route('', name: 'save', methods: ['POST'])]
    public function save(Request $request, #[CurrentUser] User $user, ProfileUpdater $updater): Response
    {
        $form = $this->createForm(ProfileType::class, $user, [
            'action' => $this->generateUrl('app_settings_profile_save'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $updater->apply($user, $form);

            return $this->redirectToRoute('app_settings_index', ['section' => 'profile']);
        }

        return $this->render('settings/_profile.html.twig', ['profileForm' => $form], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Serves the avatar. Scoped to the signed-in user's own file — the
     * filename is random, but a route that will hand back any path under the
     * avatars directory is not something to leave lying around.
     */
    #[Route('/avatar/{filename}', name: 'avatar', requirements: ['filename' => '[0-9a-f]+\.[a-z0-9]{2,5}'], methods: ['GET'])]
    public function avatar(string $filename, #[CurrentUser] User $user, AvatarStorage $avatars): Response
    {
        if ($user->getAvatar() !== $filename) {
            throw $this->createNotFoundException();
        }

        $path = $avatars->pathFor((string) $user->getId(), $filename);

        if (false === is_file($path)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path, headers: ['Cache-Control' => 'private, max-age=604800']);
    }
}
