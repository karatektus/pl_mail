<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\User\User;
use App\Repository\Push\PushDeliveryRepository;
use App\Repository\User\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Removing a registered device from the user's own settings.
 *
 * Every other way a subscription disappears is something the server decided:
 * the endpoint answered 410, Firebase said UNREGISTERED, the browser
 * re-subscribed and replaced its own row. None of those help the person whose
 * phone is buzzing twice because an old build of the app registered itself
 * under a device id nothing uses any more — that row is healthy, it is
 * delivered to successfully, and until now the only way to be rid of it was
 * SQL.
 *
 * Deliberately NOT part of WebPushController, which is the service worker's
 * JSON API and unsubscribes *this* browser by the device id it holds in
 * localStorage. This is a settings form, addressed by row id, about a device
 * that is very often not the one being sat at — the id is the only handle the
 * page has, so it is checked against the owner rather than trusted.
 *
 * **The delivery log is not touched.** PushDelivery has no foreign key to the
 * subscription precisely so that the history outlives the row (see its
 * docblock), and removing the subscription here must not be the one path that
 * quietly takes it down: "this device stopped being delivered to on Tuesday"
 * is exactly what somebody asks about a device they removed.
 */
#[Route('/settings/push-devices', name: 'app_push_device_')]
#[IsGranted('ROLE_USER')]
final class PushDeviceController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushDeliveryRepository $deliveries,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/remove', name: 'remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(Request $request, int $id): Response
    {
        if (false === $this->isCsrfTokenValid('push-device-remove' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Scoped to the owner, so another user's row id resolves to nothing
        // rather than to their device. A 404 rather than a 403 on purpose: the
        // two answers together would let anyone enumerate which subscription
        // ids exist on the install.
        $subscription = $this->subscriptions->findOneOwnedBy($id, $this->getUser());

        if (null === $subscription) {
            throw $this->createNotFoundException('No such push device.');
        }

        $this->em->remove($subscription);
        $this->em->flush();

        return $this->respond($request);
    }

    /**
     * The device list re-rendered, or a redirect back to the pane for a client
     * that did not ask for a stream — the same bargain ApiTokenController
     * strikes, so the action works with JavaScript disabled.
     */
    private function respond(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('push_device/_mutation.stream.html.twig', [
                'devices' => $this->subscriptions->findForUser($user),
                'lastDeliveries' => $this->deliveries->lastDeliveryPerDevice((int) $user->id),
                'toastMessage' => 'settings.notifications.devices.removed',
            ]);
        }

        return $this->redirectToRoute('app_settings_index', ['section' => 'notifications']);
    }
}
