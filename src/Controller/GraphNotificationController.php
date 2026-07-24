<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Account;
use App\Message\SyncAccountMessage;
use App\Repository\AccountRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Microsoft Graph push notifications.
 *
 * These routes are unauthenticated by necessity — Graph is the caller, not a
 * logged-in user — so they must be allowed anonymously in security.yaml and
 * exempted from CSRF. Authenticity comes from clientState, a 256-bit secret
 * minted per subscription and compared in constant time.
 *
 * Both endpoints do the minimum possible work and return immediately. Graph
 * expects a response within a few seconds and will retry (then eventually drop
 * the subscription) if the endpoint is slow, so the actual sync is dispatched
 * to Messenger rather than run inline.
 */
final class GraphNotificationController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository   $accountRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface     $logger,
    ) {}

    #[Route('/webhook/graph/notify', name: 'app_graph_notification', methods: ['POST'])]
    public function notify(Request $request): Response
    {
        $validation = $this->validationResponse($request);

        if (null !== $validation) {
            return $validation;
        }

        $payload = $this->decode($request);
        $seen    = [];

        foreach ($payload['value'] ?? [] as $notification) {
            $account = $this->authenticate($notification);

            if (null === $account) {
                continue;
            }

            // Graph batches notifications, and several can arrive for the same
            // account in one POST. The sync is idempotent and delta-driven, so
            // dispatching per notification is harmless — but dedup anyway to
            // avoid pointless queue churn on busy mailboxes.
            $seen[(int) $account->getId()] = true;
        }

        foreach (array_keys($seen) as $accountId) {
            $this->bus->dispatch(new SyncAccountMessage($accountId));
        }

        // 202 tells Graph the notification was accepted for processing.
        return new Response('', Response::HTTP_ACCEPTED);
    }

    /**
     * Lifecycle events warn about subscriptions that are about to break.
     *
     *   reauthorizationRequired → the token backing the subscription needs
     *                             refreshing; a sync does that as a side effect
     *   subscriptionRemoved     → Graph dropped it; clear local state so the
     *                             renewal command recreates it
     *   missed                  → Graph could not deliver some notifications;
     *                             a delta pass reconciles whatever was lost
     */
    #[Route('/webhook/graph/lifecycle', name: 'app_graph_lifecycle', methods: ['POST'])]
    public function lifecycle(Request $request): Response
    {
        $validation = $this->validationResponse($request);

        if (null !== $validation) {
            return $validation;
        }

        $payload = $this->decode($request);

        foreach ($payload['value'] ?? [] as $notification) {
            $account = $this->authenticate($notification);

            if (null === $account) {
                continue;
            }

            $event = (string) ($notification['lifecycleEvent'] ?? '');

            $this->logger->info('GraphNotification: lifecycle event', [
                'accountId' => $account->getId(),
                'event'     => $event,
            ]);

            if ('subscriptionRemoved' === $event) {
                $account
                    ->setGraphSubscriptionId(null)
                    ->setGraphSubscriptionClientState(null)
                    ->setGraphSubscriptionExpiresAt(null);
            }

            // Every lifecycle event is a reason to reconcile.
            $this->bus->dispatch(new SyncAccountMessage((int) $account->getId()));
        }

        return new Response('', Response::HTTP_ACCEPTED);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Graph validates a notification URL by POSTing to it with a
     * ?validationToken query parameter and expecting the raw token echoed back
     * as text/plain within ten seconds. This runs synchronously inside
     * createSubscription(), so it must be the very first thing checked.
     */
    private function validationResponse(Request $request): ?Response
    {
        $token = $request->query->get('validationToken');

        if (null === $token) {
            return null;
        }

        return new Response((string) $token, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * @param array<string,mixed> $notification
     */
    private function authenticate(array $notification): ?Account
    {
        $subscriptionId = (string) ($notification['subscriptionId'] ?? '');
        $clientState    = (string) ($notification['clientState'] ?? '');

        if ('' === $subscriptionId || '' === $clientState) {
            return null;
        }

        $account = $this->accountRepository->findOneBy(['graphSubscriptionId' => $subscriptionId]);

        if (null === $account) {
            $this->logger->warning('GraphNotification: unknown subscription', [
                'subscriptionId' => $subscriptionId,
            ]);

            return null;
        }

        $expected = (string) $account->getGraphSubscriptionClientState();

        if (false === hash_equals($expected, $clientState)) {
            $this->logger->warning('GraphNotification: clientState mismatch, ignoring', [
                'accountId' => $account->getId(),
            ]);

            return null;
        }

        if (true !== $account->isActive()) {
            return null;
        }

        return $account;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (false === is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
