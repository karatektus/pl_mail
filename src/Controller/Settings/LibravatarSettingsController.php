<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Service\User\Libravatar\LibravatarDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Profile → "Share my picture through Libravatar" (#642): the
 * user's own yes to the route serving their picture.
 *
 * Refused while the administrator has the route off. The switch is not
 * drawn then, and a hand-made post must not record a yes the user was never
 * shown the warning for.
 *
 * Saving drops the cached hash map (LibravatarDirectory::forget()), so a yes
 * is served at once instead of after the cache runs out. A no needs nothing:
 * the directory asks the user row on every request.
 *
 * A token, like every settings form, and here for the reason that matters
 * most: a cross-site post that switched this on would publish somebody's face.
 */
#[IsGranted('ROLE_USER')]
final class LibravatarSettingsController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/profile/libravatar', name: 'app_settings_profile_libravatar', methods: ['POST'])]
    public function update(
        Request $request,
        #[CurrentUser] User $user,
        SenderPictureSettingsRepository $settings,
        LibravatarDirectory $directory,
        EntityManagerInterface $em,
    ): Response {
        $this->assertCsrf($request, 'settings-libravatar');

        if (false === $settings->libravatarEnabled()) {
            throw $this->createAccessDeniedException();
        }

        if (true === $request->request->has('publish')) {
            $user->publishesLibravatar = '1' === (string) $request->request->get('publish');
            $em->flush();
            $directory->forget();
        }

        return $this->redirectToRoute('app_settings_page', ['page' => 'profile']);
    }
}
