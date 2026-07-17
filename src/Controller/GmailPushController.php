<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\SyncMailboxMessage;
use App\Repository\AccountRepository;
use App\Repository\MailboxRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Google Cloud Pub/Sub push notifications for Gmail changes.
 *
 * Google POSTs JSON to this endpoint whenever a watched mailbox changes.
 * The payload is a Pub/Sub message with a base64-encoded data field:
 *
 *   {
 *     "message": {
 *       "data": "<base64({ emailAddress, historyId })>",
 *       "messageId": "…",
 *       "publishTime": "…"
 *     },
 *     "subscription": "projects/…/subscriptions/…"
 *   }
 *
 * We decode the data, find the matching account's inbox mailbox, and dispatch
 * a SyncMailboxMessage so the existing handler picks it up asynchronously.
 *
 * The endpoint must return 2xx quickly or Pub/Sub will retry.
 * We do not verify the Pub/Sub JWT here — the route should be protected at
 * the infrastructure level (e.g. only allow requests from Google's IP ranges),
 * or you can add JWT verification via the google/auth library in future.
 */
#[Route('/gmail/push', name: 'app_gmail_push', methods: ['POST'])]
final class GmailPushController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository  $accountRepository,
        private readonly MailboxRepository  $mailboxRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface    $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);

        if (false === is_array($body)) {
            $this->logger->warning('GmailPush: invalid JSON body');
            // Return 200 anyway — returning 4xx causes Pub/Sub to retry forever
            return new Response('', Response::HTTP_OK);
        }

        $encodedData = $body['message']['data'] ?? null;

        if (false === is_string($encodedData)) {
            $this->logger->warning('GmailPush: missing message.data');
            return new Response('', Response::HTTP_OK);
        }

        $decoded = base64_decode($encodedData, strict: true);

        if (false === $decoded) {
            $this->logger->warning('GmailPush: base64 decode failed');
            return new Response('', Response::HTTP_OK);
        }

        $data = json_decode($decoded, true);

        if (false === is_array($data)) {
            $this->logger->warning('GmailPush: inner JSON decode failed');
            return new Response('', Response::HTTP_OK);
        }

        $emailAddress = (string) ($data['emailAddress'] ?? '');
        $historyId    = (string) ($data['historyId'] ?? '');

        $this->logger->info('GmailPush: received notification', [
            'emailAddress' => $emailAddress,
            'historyId'    => $historyId,
        ]);

        if ('' === $emailAddress) {
            $this->logger->warning('GmailPush: no emailAddress in payload');
            return new Response('', Response::HTTP_OK);
        }

        // Find the account by email
        $account = $this->accountRepository->findOneBy(['email' => $emailAddress]);

        if (null === $account) {
            $this->logger->warning('GmailPush: no account found for email', [
                'email' => $emailAddress,
            ]);
            return new Response('', Response::HTTP_OK);
        }

        // Find the inbox mailbox for this account
        $inboxMailbox = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => '\\Inbox',
        ]);

        if (null === $inboxMailbox) {
            $this->logger->warning('GmailPush: no inbox mailbox found', [
                'accountId' => $account->getId(),
            ]);
            return new Response('', Response::HTTP_OK);
        }

        // Dispatch — the handler will call GmailApiSyncer::syncIncremental()
        $this->bus->dispatch(new SyncMailboxMessage($inboxMailbox->getId()));

        $this->logger->info('GmailPush: dispatched SyncMailboxMessage', [
            'mailboxId' => $inboxMailbox->getId(),
        ]);

        return new Response('', Response::HTTP_OK);
    }
}
