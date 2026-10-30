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
 * Settings → Notifications: what a notification is worth showing, for the
 * whole user rather than one device.
 *
 * Today that is one switch, "skip mail that is already read" (User::$pushSkipsRead).
 * It is not enforced here: the browser's service worker reads it back through
 * WebPushController::inboxState() when a push arrives, so this controller only
 * records the choice. Which devices exist is PushDeviceController's business.
 */
#[IsGranted('ROLE_USER')]
final class NotificationPreferenceController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/notifications', name: 'app_settings_notifications_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-notifications');

        // Read only when posted, like every control on the settings pages: a
        // form that did not mention the switch must not move it.
        if (true === $request->request->has('skipRead')) {
            $user->pushSkipsRead = '1' === (string) $request->request->get('skipRead');
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'notifications']);
    }
}
