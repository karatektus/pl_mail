<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Monitoring\MessengerQueueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The topbar's sync button: dispatch a sync for this user's active accounts,
 * and answer how much of it is still queued so the button knows when to stop
 * spinning.
 *
 * Same effect as `app:mail:sync`, scoped to one user. It was called
 * DevSyncController and served /dev/sync from App\Controller\Dev, with a
 * docblock opening "TEMPORARY" — none of which was true any more. It is a
 * button every user sees on every page, so it lives with the rest of mail and
 * answers a /mail URL; a route under /dev is one an operator could reasonably
 * block at the proxy, and the sync button would go with it.
 */
#[Route('/mail/sync', name: 'app_mail_sync_')]
// ROLE_USER, not IS_AUTHENTICATED_FULLY: "keep me logged in" survives the
// session, so a remembered user browses the app normally but fails a FULLY
// check — the button's fetch followed the redirect to /login and choked on the
// HTML. Same level as the rest of the app (access_control ^/).
#[IsGranted('ROLE_USER')]
final class SyncController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly AccountRepository        $accountRepository,
        private readonly MailboxRepository        $mailboxRepository,
        private readonly MessengerQueueRepository $queue,
        private readonly MessageBusInterface      $bus,
    ) {}

    #[Route('', name: 'run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        // sync_now_controller.js sends the token from the csrf-token meta tag in
        // the layout head; the tag is what this check needs to be worth having.
        $this->assertCsrf($request, 'ajax');

        $accounts = $this->activeAccounts();

        foreach ($accounts as $account) {
            $this->bus->dispatch(new SyncAccountMessage($account->id));
        }

        return $this->json(['dispatched' => count($accounts)]);
    }

    /**
     * Pending + in-flight sync jobs belonging to this user.
     *
     * How the count is arrived at — matching serialised envelope bodies,
     * because the transport's table has no user column — is
     * MessengerQueueRepository::countPendingSyncWork(); it used to be inline
     * SQL here, which is the one thing this controller was doing that a
     * controller may not.
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $accounts = $this->activeAccounts();

        if ([] === $accounts) {
            return $this->json(['pending' => 0]);
        }

        return $this->json([
            'pending' => $this->queue->countPendingSyncWork(
                array_map(static fn (Account $account): int => (int) $account->id, $accounts),
                array_map(
                    static fn ($mailbox): int => (int) $mailbox->id,
                    $this->mailboxRepository->findBy(['account' => $accounts]),
                ),
            ),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return list<Account>
     */
    private function activeAccounts(): array
    {
        return array_values(array_filter(
            $this->accountRepository->findForUserOrdered($this->getUser()),
            static fn (Account $account): bool => true === $account->isActive,
        ));
    }
}
