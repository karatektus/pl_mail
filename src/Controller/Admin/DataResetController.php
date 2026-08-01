<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Maintenance\DataResetter;
use App\Service\Maintenance\ResetReport;
use App\Service\Maintenance\ResetStage;
use App\Service\Monitoring\WebProcessRestart;
use App\Service\Setup\PublicUrlSetting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `app:reset`, as buttons.
 *
 * Same authorization, CSRF handling and redirect style as
 * SystemActionController, which is the model for everything in the admin panel
 * that acts rather than reads — with one deliberate departure, below.
 *
 * Two tiers of guard, because the six stages are not equally survivable. The
 * top four delete synced data: the worst case is a resync, which costs hours
 * and no information, so they get the same data-turbo-confirm the restart and
 * purge buttons already use. The bottom two delete every user (the one
 * clicking included), every stored mailbox password and the files on disk, and
 * nothing brings any of that back. Those two require the instance name to be
 * typed into the form, checked here — not in JavaScript, which is decoration
 * rather than a guard, and not present at all for a request that did not come
 * from the page.
 *
 * The departure from the model: a full reset does not redirect. It cannot. The
 * user it was performed by no longer exists, so there is no page left behind
 * the firewall to send them to, and the response itself is the only chance to
 * tell them what happened. See finishFullReset().
 */
#[Route('/admin/reset', name: 'app_admin_reset_')]
#[IsGranted('ROLE_ADMIN')]
final class DataResetController extends AbstractController
{
    /** Sent back on the redirect when the typed confirmation did not match. */
    public const string ERROR_CONFIRMATION_MISMATCH = 'confirmation-mismatch';

    public function __construct(
        private readonly DataResetter      $resetter,
        private readonly PublicUrlSetting  $publicUrl,
        private readonly WebProcessRestart $webRestart,
        private readonly Security          $security,
    ) {}

    /**
     * The panel itself. Its own frame rather than part of the auto-refreshed
     * live one: a poll every ten seconds would wipe the confirmation field
     * mid-word, and the whole point of that field is that it takes deliberate
     * typing to fill in.
     */
    #[Route('/panel', name: 'panel', methods: ['GET'])]
    public function panel(Request $request): Response
    {
        return $this->render('admin/_reset_frame.html.twig', [
            'ordinaryStages'      => ResetStage::ordinary(),
            'unrecoverableStages' => ResetStage::unrecoverable(),
            'instanceName'        => $this->instanceName($request),
            'restartSupported'    => $this->webRestart->isSupported(),
            'error'               => $request->query->get('error'),
        ]);
    }

    #[Route('/run/{stage}', name: 'run', methods: ['POST'])]
    public function run(Request $request, string $stage): Response
    {
        $resetStage = ResetStage::tryFrom($stage);

        if (null === $resetStage) {
            throw $this->createNotFoundException('Unknown reset stage.');
        }

        // Per stage, not one shared id: a token minted for "clear synced mail"
        // must not be replayable against "delete everything".
        $this->validateCsrf($request, $resetStage->csrfTokenId());

        if (true === $resetStage->needsTypedConfirmation() && false === $this->confirmationMatches($request)) {
            return $this->redirectToPanel(self::ERROR_CONFIRMATION_MISMATCH);
        }

        $scope = $resetStage->scope();

        if (null === $scope) {
            return $this->finishFullReset($this->resetter->fullReset($resetStage->rotatesSecrets()), $resetStage);
        }

        $this->resetter->reset($scope);

        return $this->redirectToPanel();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The last authenticated moment of this install's life.
     *
     * Everything the operator needs to know has to be said now, in this
     * response. The next request will not have a user — their row was truncated
     * a few lines ago — so /admin is closed to them, and any redirect there
     * would be answered with a 403 that the entry point turns into /login, and
     * /login into /install. That chain happens to end in the right place, but
     * it presents the most destructive operation in the app as an access
     * failure and says nothing about the restart a rotated secret requires.
     *
     * So: sign them out deliberately rather than leaving a token pointing at a
     * deleted row for UserProvider::refreshUser() to trip over, and render the
     * summary directly. The form that posts here carries data-turbo="false",
     * because Turbo requires a form submission to answer with a redirect and
     * would otherwise refuse to display this page at all.
     */
    private function finishFullReset(ResetReport $report, ResetStage $stage): Response
    {
        // Asked for before the sign-out, honoured after the response is sent.
        // Only for a rotation: the app is genuinely unusable until the web
        // process re-reads the secrets, whereas a full reset that kept them
        // needs nothing but a page load.
        $restartRequested = true === $stage->rotatesSecrets() && $this->webRestart->request();

        // Invalidates the session and clears the remember-me cookie, which
        // matters as much as the token does: that cookie is a signature over a
        // user that is gone, so leaving it would mean a failed authentication
        // attempt on every subsequent request rather than a clean anonymous one.
        $this->security->logout(false);

        return $this->render('admin/reset_done.html.twig', [
            'rotatedSecrets'   => $stage->rotatesSecrets(),
            'removedSecrets'   => $report->removedSecrets,
            'workersSignalled' => $report->workersSignalled,
            'restartRequested' => $restartRequested,
            'tableCount'       => count($report->tables),
        ]);
    }

    /**
     * The value the two unrecoverable stages ask to be typed out.
     *
     * The host plMail answers on: known to the operator, printed next to the
     * field, and — unlike "yes" or "delete" — not something a hand already
     * moving towards a button produces by itself. Falls back to the host of the
     * request when APP_PUBLIC_URL has never been set, so the guard exists even
     * on an install that skipped setup.
     */
    private function instanceName(Request $request): string
    {
        $configured = $this->publicUrl->current();

        if (null === $configured) {
            return $request->getHost();
        }

        return parse_url($configured, PHP_URL_HOST) ?: $request->getHost();
    }

    /**
     * Compared case-insensitively and trimmed: a phone that capitalised the
     * first letter, or a copy-paste that brought a space, is not the mistake
     * this is guarding against, and rejecting it teaches the operator to stop
     * reading the field.
     */
    private function confirmationMatches(Request $request): bool
    {
        $typed = trim((string) $request->request->get('confirmation', ''));

        return '' !== $typed
            && 0 === strcasecmp($typed, $this->instanceName($request));
    }

    private function redirectToPanel(?string $error = null): Response
    {
        return $this->redirectToRoute('app_admin_dashboard', array_filter([
            'section' => 'reset',
            'error'   => $error,
        ]));
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token', '');

        if (false === $this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
