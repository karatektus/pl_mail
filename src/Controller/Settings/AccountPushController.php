<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Mail\Account;
use App\Security\Voter\OwnershipVoter;
use App\Service\Push\PushStatusFactory;
use App\Service\Push\PushSubscriptionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-account delivery mode toggle: push or scheduled polling.
 *
 * Provider-agnostic — the registry resolves Gmail (users.watch + Cloud Pub/Sub)
 * or Microsoft (Graph subscriptions). IMAP accounts have no push manager and
 * never render the control.
 *
 * Push needs the instance to be reachable from the internet, which for most
 * self-hosted deployments means a correctly configured reverse proxy. When
 * registration fails, the flag is rolled back so the UI never claims push is on
 * while nothing is being delivered.
 *
 * Turbo-native: the toggle re-renders its own frame, matching the existing
 * enable/disable control in the accounts pane.
 */
#[IsGranted('ROLE_USER')]
final class AccountPushController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRegistry $registry,
        private readonly PushStatusFactory        $statusFactory,
        private readonly EntityManagerInterface   $em,
        private readonly TranslatorInterface      $translator,
    ) {}

    #[Route('/settings/accounts/{id}/push', name: 'settings_account_push_toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $token = (string) $request->request->get('_token');

        if (false === $this->isCsrfTokenValid('account_push_' . $account->id, $token)) {
            throw $this->createAccessDeniedException();
        }

        $manager = $this->registry->resolve($account);

        if (null === $manager) {
            throw $this->createNotFoundException('This account provider does not support push delivery.');
        }

        $failed = false;

        if (false === $account->pushEnabled) {
            // subscribe() reads the flag, so it has to be set and flushed first.
            $account->pushEnabled = true;
            $this->em->flush();

            if (false === $manager->subscribe($account)) {
                $account->pushEnabled = false;
                $this->em->flush();

                $failed = true;
            }
        } else {
            $manager->unsubscribe($account);

            $account->pushEnabled = false;
            $this->em->flush();
        }

        return $this->renderToggle($account, $failed);
    }

    /**
     * Re-register push without toggling — the user-facing counterpart to
     * `app:push:renew --repair`, for the two broken states where the toggle
     * looks on but nothing is arriving.
     *
     * ── Two callers, two shapes of answer ────────────────────────────────────
     * The accounts pane submits this from inside `<turbo-frame
     * id="account-push-N">` and wants the frame back, which is what
     * renderToggle() produces.
     *
     * The health page has no such frame. It submitted the same form and got a
     * bare turbo-frame fragment back for a frame that is not on the page — and
     * the visible result was a health page that came back looking *identical*,
     * with nothing whatsoever to say the button had been pressed, let alone
     * whether re-registering had worked. A repair that reports nothing is
     * indistinguishable from a repair that did nothing, and re-registering can
     * genuinely fail (a dead grant, a missing Pub/Sub topic), so "it looks the
     * same" is not even reliably good news.
     *
     * So a submission that is not addressed to a frame gets a redirect and a
     * flash that says which of the two happened. Detected on the `Turbo-Frame`
     * header, which Turbo sets only for frame-targeted submissions — the
     * request tells us what shape of answer it can use, rather than this
     * guessing from a referer.
     */
    #[Route('/settings/accounts/{id}/push/repair', name: 'settings_account_push_repair', methods: ['POST'])]
    public function repair(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $token = (string) $request->request->get('_token');

        if (false === $this->isCsrfTokenValid('account_push_' . $account->id, $token)) {
            throw $this->createAccessDeniedException();
        }

        $manager = $this->registry->resolve($account);

        if (null === $manager) {
            throw $this->createNotFoundException('This account provider does not support push delivery.');
        }

        $failed = false === $manager->renew($account);

        if ('' === (string) $request->headers->get('Turbo-Frame')) {
            // Translated here, not in the template: the flash renderer prints
            // what it is given, which is the idiom AccountHealthController's
            // repairs already use.
            $this->addFlash(
                true === $failed ? 'error' : 'success',
                $this->translator->trans(
                    true === $failed
                        ? 'settings.health.flash.push_repair_failed'
                        : 'settings.health.flash.push_repaired',
                    ['%account%' => $account->email],
                ),
            );

            return $this->redirectToRoute('app_settings_index', ['section' => 'health']);
        }

        return $this->renderToggle($account, $failed);
    }

    /**
     * The partial resolves its own state via push_status(), so the only thing
     * worth passing explicitly is the outcome of the action just performed —
     * a failure is not derivable from the account afterwards.
     */
    private function renderToggle(Account $account, bool $failed): Response
    {
        $status = $this->statusFactory->build($account);

        if (true === $failed) {
            $status = $status->withFailure();
        }

        // The compact row control, since both actions are triggered from the
        // accounts list. If the roomier card in the edit pane ever posts here
        // too, pass the template name in rather than branching on the referer.
        return $this->render('settings/accounts/_push_control.html.twig', [
            'account' => $account,
            'status'  => $status,
        ]);
    }

}
