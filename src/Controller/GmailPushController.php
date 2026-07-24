<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Account;
use App\Message\SyncAccountMessage;
use App\Repository\AccountRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Gmail push notifications forwarded by Cloud Pub/Sub.
 *
 * Two security fixes over the previous implementation, both worth calling out:
 *
 * 1. The endpoint was entirely unauthenticated and trusted the emailAddress in
 *    the payload verbatim. Anyone able to POST here could trigger a sync for
 *    any account by guessing an address. It leaked no data, but it was a free
 *    remote trigger for arbitrary API work. Pub/Sub push subscriptions support
 *    a shared secret in the URL, so that is now required and compared with
 *    hash_equals — the analogue of Graph's clientState.
 *
 * 2. findOneBy(['email' => …]) is not user-scoped. With two users who have
 *    connected the same Gmail address, exactly one of them would ever sync and
 *    which one was arbitrary. All matching accounts are now dispatched.
 *
 * Always returns 200/204. A non-2xx makes Pub/Sub redeliver with backoff, and
 * for a payload we cannot attribute, redelivery will never help.
 */
final class GmailPushController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository      $accountRepository,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        #[Autowire(env: 'GMAIL_PUBSUB_VERIFICATION_TOKEN')]
        private readonly string                 $verificationToken,
    ) {}

    #[Route('/gmail/push', name: 'app_gmail_push', methods: ['POST'])]
    public function push(Request $request): Response
    {
        if (false === $this->isAuthentic($request)) {
            $this->logger->warning('GmailPush: rejected notification with bad or missing token');

            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $data = $this->decodeMessageData($request);

        if (null === $data) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $emailAddress = (string) ($data['emailAddress'] ?? '');
        $historyId    = (string) ($data['historyId'] ?? '');

        if ('' === $emailAddress) {
            $this->logger->warning('GmailPush: no emailAddress in payload');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $accounts = $this->accountRepository->findBy([
            'email'    => $emailAddress,
            'isActive' => true,
        ]);

        $dispatched = 0;

        foreach ($accounts as $account) {
            if (false === $this->isWatched($account)) {
                continue;
            }

            $account->setGmailLastPushAt(new DateTimeImmutable());

            $this->bus->dispatch(new SyncAccountMessage((int) $account->getId()));
            $dispatched++;
        }

        if ($dispatched > 0) {
            $this->em->flush();
        }

        $this->logger->info('GmailPush: notification handled', [
            'email'      => $emailAddress,
            'historyId'  => $historyId,
            'dispatched' => $dispatched,
        ]);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Pub/Sub appends the shared secret configured on the push subscription:
     *   https://mail.example.com/gmail/push?token=…
     *
     * If no token is configured the endpoint refuses everything rather than
     * silently running unauthenticated — failing closed is the right default
     * for something reachable from the internet.
     */
    private function isAuthentic(Request $request): bool
    {
        $expected = trim($this->verificationToken);

        if ('' === $expected) {
            return false;
        }

        $provided = (string) $request->query->get('token', '');

        if ('' === $provided) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Only accounts that actually asked for push and hold a live watch should
     * be woken by a notification — otherwise a stale Pub/Sub subscription keeps
     * driving syncs for an account the user has switched back to polling.
     */
    private function isWatched(Account $account): bool
    {
        if (false === $account->isGmail()) {
            return false;
        }

        if (true !== $account->isPushEnabled()) {
            return false;
        }

        return null !== $account->getGmailWatchExpiry();
    }

    /**
     * Pub/Sub wraps the payload: {"message": {"data": "<base64 JSON>"}}.
     *
     * @return array<string,mixed>|null
     */
    private function decodeMessageData(Request $request): ?array
    {
        $envelope = json_decode($request->getContent(), true);

        if (false === is_array($envelope)) {
            $this->logger->warning('GmailPush: unparseable envelope');

            return null;
        }

        $encoded = $envelope['message']['data'] ?? null;

        if (false === is_string($encoded) || '' === $encoded) {
            $this->logger->warning('GmailPush: envelope carried no data');

            return null;
        }

        $decoded = base64_decode($encoded, true);

        if (false === $decoded) {
            $this->logger->warning('GmailPush: data was not valid base64');

            return null;
        }

        $payload = json_decode($decoded, true);

        if (false === is_array($payload)) {
            $this->logger->warning('GmailPush: data was not valid JSON');

            return null;
        }

        return $payload;
    }
}
