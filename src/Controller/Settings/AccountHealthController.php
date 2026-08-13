<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Service\Monitoring\QueueMonitor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The repairs offered on the health page.
 *
 * Only the ones that had no home already. Push re-subscription belongs to
 * AccountPushController and the integration reconnect belongs to the
 * integration OAuth flow; the health page links to both rather than growing
 * duplicates, because a second endpoint doing the same thing is a second
 * endpoint to get the ownership check right on.
 *
 * ── What every action here promises ──────────────────────────────────────────
 * Ownership is checked before anything is read, CSRF before anything is
 * written, and each one reports what actually happened rather than assuming it
 * worked. The user is repairing credentialed access to their own mail; an
 * action here that guessed would be guessing with their mailbox.
 *
 * The reconnect itself is NOT here — it is a redirect into the existing OAuth
 * flow (see OAuthController::connect, reconnect mode), because the round trip
 * through the provider cannot be a POST from a page.
 */
#[Route('/settings/health', name: 'app_health_')]
#[IsGranted('ROLE_USER')]
final class AccountHealthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly QueueMonitor           $queueMonitor,
        private readonly TranslatorInterface    $translator,
    ) {
    }

    /**
     * Start a reconnect for one account.
     *
     * A GET that redirects, because its destination is the provider's consent
     * screen. It writes nothing itself — the account is not touched until the
     * callback comes back with a token AND the address has been checked — so
     * there is no state change here for a CSRF token to protect. The ownership
     * check still runs, so this cannot be used to discover whose account an id
     * belongs to.
     */
    #[Route('/reconnect/{id}', name: 'reconnect', methods: ['GET'])]
    public function reconnect(Account $account): RedirectResponse
    {
        $this->denyUnlessOwner($account);

        $provider = $account->oauthProvider;

        if (null === $provider) {
            // A password account has nothing to re-consent to; its repair is
            // the ordinary edit form. Reaching this by hand is not worth a
            // friendly page.
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('app_oauth_connect', [
            'provider'  => $provider,
            'reconnect' => $account->id,
        ]);
    }

    /**
     * Ask one calendar to try again now.
     *
     * Dispatched rather than run inline, matching CalendarSyncCommand: a
     * hand-triggered sync that took a different path from the scheduled one
     * would have different failure behaviour, which is how "it works when I do
     * it myself" becomes a support thread.
     *
     * The backoff is cleared first, and that is as much the point of the button
     * as the dispatch is. The handler itself does not check the window — a push
     * notification or a hand-run sync is a reason to try whatever the schedule
     * says — so the dispatch alone would work. What it would not do is undo the
     * accumulated schedule: without the reset, a calendar that had climbed to a
     * day-long window would attempt once now and then go quiet for another day,
     * even if the user had just repaired the thing that was breaking it.
     */
    #[Route('/calendar/{id}/resync', name: 'calendar_resync', methods: ['POST'])]
    public function resyncCalendar(Request $request, Calendar $calendar): RedirectResponse
    {
        if ($calendar->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $this->assertToken($request, 'health_calendar_' . $calendar->id);

        $calendar->clearSyncBackoff();
        $this->em->flush();

        $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));

        $this->addFlash('success', $this->translator->trans('settings.health.flash.calendar_resync', [
            '%calendar%' => $calendar->name,
        ]));

        return $this->back();
    }

    /**
     * Put abandoned background work back on the queue.
     *
     * Admin-gated for the reason AccountHealthInspector::abandonedWork()
     * documents: the failure transport is instance-wide, so this is not a
     * per-user action and must not be offered as one.
     *
     * Safe by nature — retrying work that fails again simply lands back where
     * it was — which is why it is the primary of the two queue buttons.
     */
    #[Route('/queue/retry', name: 'queue_retry', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function retryQueue(Request $request): RedirectResponse
    {
        $this->assertToken($request, 'health_queue_retry');

        $retried = $this->queueMonitor->retryAll();

        // The count is reported rather than a bare "done": retrying nothing and
        // retrying four hundred things look identical otherwise, and the first
        // usually means somebody else got there first.
        $this->addFlash('success', $this->translator->trans('settings.health.flash.queue_retried', [
            '%count%' => $retried,
        ]));

        return $this->back();
    }

    /**
     * Throw abandoned work away without retrying it.
     *
     * Destructive, and rendered apart from the safe repairs with that said in
     * words. There is no undo — the envelopes are gone — so the page states
     * that before the button rather than after it.
     */
    #[Route('/queue/discard', name: 'queue_discard', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function discardQueue(Request $request): RedirectResponse
    {
        $this->assertToken($request, 'health_queue_discard');

        $purged = $this->queueMonitor->purgeAll();

        $this->addFlash('success', $this->translator->trans('settings.health.flash.queue_discarded', [
            '%count%' => $purged,
        ]));

        return $this->back();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Per-action tokens, the way AliasController::assertToken() does it.
     *
     * One id per action per subject rather than the shared `ajax` token: these
     * actions touch account credentials and background work, and a single token
     * good for all of them makes any one XSS worth every one of them.
     */
    private function assertToken(Request $request, string $id): void
    {
        $token = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token') ?? '');

        if (false === $this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessOwner(Account $account): void
    {
        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function back(): RedirectResponse
    {
        return $this->redirectToRoute('app_settings_index', ['section' => 'health']);
    }
}
