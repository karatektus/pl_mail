<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\MessageCategory;
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
 * Two choices, both for the browsers: which tabs notify (User::$pushCategories,
 * enforced where the push is sent, in PushDispatcher) and whether mail that is
 * already read is skipped (User::$pushSkipsRead, read back by the service worker
 * through WebPushController::inboxState()). This controller only records them.
 * Which devices exist is PushDeviceController's business.
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

        // The tab boxes are posted with a marker, because an unchecked box sends
        // nothing and "no field" must still mean "leave it alone". A post that
        // unchecks every box changes nothing either: no tab at all is
        // notifications switched off, which has a switch of its own.
        if (true === $request->request->has('categoriesSent')) {
            $chosen = [];

            foreach ($request->request->all('categories') as $value) {
                $category = MessageCategory::tryFrom((string) $value);

                if (null !== $category) {
                    $chosen[] = $category;
                }
            }

            if ([] !== $chosen) {
                $user->pushCategories = $chosen;
            }
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'notifications']);
    }
}
