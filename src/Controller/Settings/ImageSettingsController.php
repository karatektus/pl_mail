<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Mail → Images: whether remote images in mail wait to be asked
 * for (#124).
 *
 * One switch, off until somebody turns it on. MessageRenderer is what reads
 * it; this controller only records the choice.
 *
 * Not the per-sender allowlist. That is still built from the "Always show
 * images from …" button on a message (Mail\RemoteImageController), and this
 * switch does not touch it: switching it off again puts back exactly the
 * senders somebody had trusted before.
 *
 * A token like every other settings form, and here for the reason the
 * allowlist's own buttons carry one: a cross-site POST that turned this on
 * would make every later message a tracking pixel the reader never agreed to.
 */
#[IsGranted('ROLE_USER')]
final class ImageSettingsController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/images', name: 'app_settings_images_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-images');

        // Read only when posted, like every control on the settings pages: a
        // form that did not mention the switch must not move it.
        if (true === $request->request->has('alwaysShow')) {
            $user->alwaysShowImages = '1' === (string) $request->request->get('alwaysShow');
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'images']);
    }
}
