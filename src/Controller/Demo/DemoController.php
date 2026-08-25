<?php

declare(strict_types=1);

namespace App\Controller\Demo;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Security\LoginFormAuthenticator;
use App\Service\Demo\DemoInbox;
use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoProvisioner;
use App\Service\Demo\DemoScenarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * The two things a demo visitor can do that a normal user cannot: be handed an
 * account, and make mail arrive.
 *
 * Both 404 when demo mode is off, rather than redirecting or forbidding. A
 * redirect confirms the endpoint exists and is merely closed — the same
 * reasoning as InstallGuard, and it matters more here: /demo mints a logged-in
 * user without asking anyone for credentials, so on a normal install it must be
 * as if the route had never been routed.
 */
final class DemoController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly DemoMode                    $demoMode,
        private readonly DemoProvisioner             $provisioner,
        private readonly DemoInbox                   $inbox,
        private readonly DemoScenarios               $scenarios,
        private readonly AccountRepository           $accounts,
        private readonly EntityManagerInterface      $entityManager,
        private readonly RateLimiterFactoryInterface $demoProvisionLimiter,
        private readonly TranslatorInterface         $translator,
    ) {
    }

    /**
     * Start a demo: provision a visitor and drop them in their inbox.
     *
     * A GET that creates rows, which is normally the wrong shape. It is the
     * right one here because this is the link people arrive on — from the
     * README, from a chat message, from a talk — and a landing page whose only
     * content is a button that says "really?" is a worse first impression than
     * the inbox itself. Nothing it creates belongs to anyone, nothing it
     * destroys existed, and the rate limiter bounds the volume.
     *
     * Somebody who already has a session keeps it. Otherwise every reload
     * would abandon the mailbox they had been reading and hand them a fresh
     * one, which on a demo reads as the app losing their mail.
     */
    #[Route('/demo', name: 'app_demo_start', methods: ['GET'])]
    public function start(Request $request, Security $security): Response
    {
        $this->assertDemoMode();

        if (null !== $security->getUser()) {
            return $this->redirectToRoute('app_default_index');
        }

        $limiter = $this->demoProvisionLimiter->create($request->getClientIp() ?? 'unknown');

        // Consumed before the work, not after: a limiter checked once the rows
        // are written is a limiter that only reports the flood it allowed.
        if (false === $limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many demo sessions from this address.');
        }

        $user = $this->provisioner->provision();

        $security->login($user, LoginFormAuthenticator::class);

        return $this->redirectToRoute('app_default_index');
    }

    /**
     * Deliver the next scripted message, as though it had just been synced.
     *
     * The button behind this is the answer to the thing a demo cannot
     * otherwise show: plMail's whole argument is what happens when mail
     * arrives, and a demo instance is by definition one no mail arrives at.
     * A visitor pressing this sees the sidebar count move, the thread appear
     * without a reload and their own filters run against it.
     */
    #[Route('/demo/receive', name: 'app_demo_receive', methods: ['POST'])]
    public function receive(Request $request): Response
    {
        $this->assertDemoMode();

        $this->assertCsrf($request, 'demo_receive');

        $user = $this->getUser();

        if (false === $user instanceof User) {
            // The firewall guarantees somebody is signed in; it does not
            // guarantee which class of user, and everything below reads
            // settings off ours.
            throw $this->createAccessDeniedException();
        }

        $account = $this->accounts->findOneBy(['usr' => $user]);

        if (null === $account) {
            // A demo user without their account is a reaped or half-provisioned
            // one. Sending them back to the start hands them a working mailbox,
            // which is the only useful thing to do with them.
            return $this->redirectToRoute('app_demo_start');
        }

        [$scenario, $nextCursor] = $this->scenarios->next($user);

        $this->inbox->deliver($account, $scenario);

        // Advanced only now the delivery has happened — see DemoScenarios::next.
        $user->setSetting(DemoScenarios::SETTING_CURSOR, $nextCursor);

        $this->entityManager->flush();

        // A sentence, not a key: the flash bag holds translated text here —
        // see the toast region in _layout/app.html.twig.
        $this->addFlash('success', $this->translator->trans('demo.flash.received', [
            '%subject%' => $scenario->subject,
        ]));

        return $this->redirect($this->backTo($request));
    }

    /**
     * Where to send the visitor after a delivery: the page they pressed the
     * button on, if that page was ours.
     *
     * Referer is attacker-controlled, so it is checked rather than trusted —
     * handed straight to redirect() this would be an open redirect on a public
     * endpoint, which is a phishing primitive whatever the page behind it does.
     * Only the path is kept, and only when the header names this host.
     */
    private function backTo(Request $request): string
    {
        $referer = $request->headers->get('referer');
        $home    = $this->generateUrl('app_default_index');

        if (null === $referer) {
            return $home;
        }

        $parts = parse_url($referer);

        if (false === is_array($parts) || ($parts['host'] ?? null) !== $request->getHost()) {
            return $home;
        }

        $path = $parts['path'] ?? '/';

        return isset($parts['query']) ? $path.'?'.$parts['query'] : $path;
    }

    private function assertDemoMode(): void
    {
        if (false === $this->demoMode->isEnabled()) {
            throw new NotFoundHttpException();
        }
    }
}
