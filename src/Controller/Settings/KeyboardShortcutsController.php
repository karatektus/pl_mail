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
 * The keyboard shortcuts: whether somebody has them, and the list of them.
 *
 * Two routes that are one subject. The list is served rather than built in the
 * browser so it is translated where everything else is, and it is served to
 * anybody signed in — including somebody who has the shortcuts switched off,
 * who is exactly the person deciding whether to switch them on.
 *
 * What the keys DO is not here and not on the server at all: each one presses
 * a control the page already has. See assets/controllers/ui/shortcuts_controller.js.
 */
#[IsGranted('ROLE_USER')]
final class KeyboardShortcutsController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/shortcuts', name: 'app_settings_shortcuts_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-shortcuts');

        // Read only when posted, like every control on this page: a form that
        // did not mention the switch must not move it.
        if (true === $request->request->has('enabled')) {
            $user->keyboardShortcuts = '1' === (string) $request->request->get('enabled');
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'shortcuts']);
    }

    #[Route('/shortcuts', name: 'app_shortcuts_help', methods: ['GET'])]
    public function help(): Response
    {
        return $this->render('shortcuts/help.html.twig');
    }
}
