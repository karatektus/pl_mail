<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\PushTransport;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Push\WebPushSender;
use App\Repository\User\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Browser push registration for the plMail PWA.
 *
 * Session-authenticated rather than going through /jmap/api: the web app has a
 * cookie, not a bearer token, and the service worker needs to post the
 * verification code back with that same cookie. The rows it creates are
 * ordinary JMAP PushSubscriptions — a device registered here is visible to
 * PushSubscription/get and delivered to by exactly the same sender.
 *
 * The handshake is NOT skipped just because this is first-party. The service
 * worker echoes the code back through /verify, which means the same guarantee
 * a third-party client gets: the endpoint provably reaches this user's device.
 */
#[Route('/settings/push', name: 'app_web_push_')]
#[IsGranted('ROLE_USER')]
final class WebPushController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly WebPushSender $sender,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/subscribe', name: 'subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (false === $this->sender->isConfigured()) {
            return $this->json(['error' => 'Web Push is not configured on this server.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = $this->decode($request);

        $endpoint = $payload['endpoint'] ?? null;
        $p256dh = $payload['keys']['p256dh'] ?? null;
        $auth = $payload['keys']['auth'] ?? null;
        $deviceClientId = $payload['deviceClientId'] ?? null;

        if (
            false === is_string($endpoint) || '' === $endpoint
            || false === is_string($p256dh) || '' === $p256dh
            || false === is_string($auth) || '' === $auth
            || false === is_string($deviceClientId) || '' === $deviceClientId
        ) {
            return $this->json(['error' => 'endpoint, keys and deviceClientId are required.'], Response::HTTP_BAD_REQUEST);
        }

        // Re-subscribing from the same browser replaces its row rather than
        // piling up dead endpoints — the browser rotates the endpoint URL but
        // keeps the same deviceClientId in localStorage.
        $subscription = $this->subscriptions->findOneByDeviceClientId($user, $deviceClientId);

        // A browser that once registered through a UnifiedPush distributor and
        // is now subscribing directly keeps its deviceClientId, and the two
        // transports have no row shape in common — so the old one goes rather
        // than being half-overwritten. Flushed alone because (usr_id,
        // device_client_id) is unique and Doctrine inserts before it deletes.
        if (null !== $subscription && PushTransport::WebPush !== $subscription->transport) {
            $this->em->remove($subscription);
            $this->em->flush();
            $subscription = null;
        }

        if (null === $subscription) {
            $subscription = PushSubscription::webPush($user, $deviceClientId, $endpoint);
            $this->em->persist($subscription);
        } else {
            $subscription->pointAt($endpoint);
        }

        $subscription->p256dh = $p256dh;
        $subscription->auth = $auth;
        $this->em->flush();

        // The service worker receives this and posts the code back to /verify.
        $this->sender->send($subscription, [
            '@type' => 'PushVerification',
            'pushSubscriptionId' => (string) $subscription->id,
            'verificationCode' => (string) $subscription->verificationCode,
        ]);

        return $this->json([
            'id' => (string) $subscription->id,
            'verified' => $subscription->verified,
        ]);
    }

    /**
     * Called by the service worker, not by a page.
     */
    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $payload = $this->decode($request);

        $id = $payload['pushSubscriptionId'] ?? null;
        $code = $payload['verificationCode'] ?? null;

        if (false === is_string($id) || false === ctype_digit($id) || false === is_string($code)) {
            return $this->json(['error' => 'Invalid verification payload.'], Response::HTTP_BAD_REQUEST);
        }

        $subscription = $this->subscriptions->findOneOwnedBy((int) $id, $this->getUser());

        if (null === $subscription) {
            return $this->json(['error' => 'No such subscription.'], Response::HTTP_NOT_FOUND);
        }

        if (false === $subscription->verify($code)) {
            return $this->json(['error' => 'Verification code does not match.'], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return $this->json(['verified' => true]);
    }

    #[Route('/unsubscribe', name: 'unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $payload = $this->decode($request);
        $deviceClientId = $payload['deviceClientId'] ?? null;

        if (false === is_string($deviceClientId) || '' === $deviceClientId) {
            return $this->json(['error' => 'deviceClientId is required.'], Response::HTTP_BAD_REQUEST);
        }

        $subscription = $this->subscriptions->findOneByDeviceClientId($this->getUser(), $deviceClientId);

        if (null !== $subscription) {
            $this->em->remove($subscription);
            $this->em->flush();
        }

        return $this->json(['removed' => true]);
    }

    /**
     * Reports whether THIS browser is registered, so the settings toggle can
     * render the right state on load.
     */
    #[Route('/status/{deviceClientId}', name: 'status', methods: ['GET'])]
    public function status(string $deviceClientId): JsonResponse
    {
        $subscription = $this->subscriptions->findOneByDeviceClientId($this->getUser(), $deviceClientId);

        return $this->json([
            'configured' => $this->sender->isConfigured(),
            'registered' => null !== $subscription,
            'verified' => null !== $subscription && $subscription->verified,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
