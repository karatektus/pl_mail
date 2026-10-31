<?php

declare(strict_types=1);

namespace App\Controller\Webhook;

use App\Infrastructure\Messaging\Message\ConfirmJmapPushMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\JmapClient\JmapPushFanout;
use App\Service\JmapClient\JmapPushSubscriptionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives what a JMAP server pushes to a PushSubscription (RFC 8620 §7.2).
 *
 * Unauthenticated by necessity, as the Graph and Google webhooks are — the
 * server is the caller — so allowed anonymously in security.yaml. What proves
 * a POST is the token in its path, minted per subscription; see
 * JmapPushSubscriptionManager. A wrong token and an unknown account both get
 * the same 404, so the endpoint says nothing about which accounts exist.
 *
 * Two bodies arrive here. A PushVerification is queued to be echoed back,
 * which is what turns the server's pushing on. A StateChange is handed to
 * JmapPushFanout, the same answer the EventSource stream gets. Both are
 * answered at once: the server is waiting, and the work is on the queue.
 */
final class JmapPushController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository           $accounts,
        private readonly JmapPushSubscriptionManager $manager,
        private readonly JmapPushFanout              $fanout,
        private readonly MessageBusInterface         $bus,
    ) {
    }

    #[Route('/webhook/jmap/{accountId}/{token}', name: 'app_jmap_push', requirements: ['accountId' => '\d+', 'token' => '[0-9a-f]{64}'], methods: ['POST'])]
    public function push(Request $request, int $accountId, string $token): Response
    {
        $account = $this->accounts->find($accountId);

        if (null === $account || false === $account->isJmap() || false === $this->manager->isAuthentic($account, $token)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);

        if (false === is_array($body)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $type = $body['@type'] ?? null;

        if ('PushVerification' === $type) {
            $subscriptionId = $body['pushSubscriptionId'] ?? null;
            $code           = $body['verificationCode'] ?? null;

            if (true === is_string($subscriptionId) && true === is_string($code)) {
                $this->bus->dispatch(new ConfirmJmapPushMessage($accountId, $subscriptionId, $code));
            }

            return new Response('', Response::HTTP_ACCEPTED);
        }

        if ('StateChange' === $type && true === $account->pushEnabled) {
            $this->fanout->dispatch($account, $body, null);
        }

        return new Response('', Response::HTTP_ACCEPTED);
    }
}
