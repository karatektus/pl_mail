<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Push\NotifyScope;
use App\Entity\User\User;
use App\Service\Mail\NotifyScopes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Notifications: what mail is worth a notification, for the whole
 * user rather than one device (#369).
 *
 * Two choices. Which switches are on — a tab each, a label each —
 * (User::$notifyScopes), which PushDispatcher applies to the browsers and the
 * phone apps read over JMAP; and whether mail that has been read in the
 * meantime is dropped (User::$notifySkipsRead), which the service worker reads
 * back through WebPushController::inboxState(). This controller only records
 * them. Which devices exist is PushDeviceController's business.
 */
#[IsGranted('ROLE_USER')]
final class NotificationPreferenceController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/notifications', name: 'app_settings_notifications_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em, NotifyScopes $notifyScopes): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-notifications');

        // Read only when posted, like every control on the settings pages: a
        // form that did not mention the switch must not move it.
        if (true === $request->request->has('skipRead')) {
            $user->notifySkipsRead = '1' === (string) $request->request->get('skipRead');
        }

        // The boxes are posted with a marker, because an unchecked box sends
        // nothing and "no field" must still mean "leave it alone". Unchecking
        // every box is allowed and means no mail notifies, as on the phone.
        if (true === $request->request->has('scopesSent')) {
            $user->notifyScopes = $this->merge($user, $notifyScopes, $request->request->all('scopes'));
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_page', ['page' => 'notifications']);
    }

    /**
     * The posted boxes, plus whatever is on that this page does not draw.
     *
     * The page shows the switches that exist now. A key for a tab or label the
     * page no longer offers — deleted on the web, or a choice the phone made
     * that this page has no box for — is kept rather than dropped by a save
     * that never mentioned it.
     *
     * @param array<mixed> $posted
     *
     * @return list<string>
     */
    private function merge(User $user, NotifyScopes $notifyScopes, array $posted): array
    {
        $offered = $notifyScopes->offeredTo($user);
        $drawn   = array_column($offered['inbox'], 'scope');

        foreach ($offered['labels'] as $label) {
            $drawn[] = NotifyScope::label((int) $label->id);
        }

        $kept = array_values(array_filter(
            $user->notifyScopes,
            static fn (string $scope): bool => false === in_array($scope, $drawn, true),
        ));

        $chosen = array_values(array_filter(
            array_map('strval', array_filter($posted, 'is_scalar')),
            static fn (string $scope): bool => true === in_array($scope, $drawn, true),
        ));

        return [...$kept, ...$chosen];
    }
}
