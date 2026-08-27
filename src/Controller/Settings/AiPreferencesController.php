<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Ai\ReplyContext;
use App\Entity\Ai\AiFeature;
use App\Entity\User\User;
use App\Service\Ai\AiAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The three writes behind Settings → Assistant.
 *
 * THREE ACTIONS AND NOT ONE, BECAUSE THEY ARE THREE DIFFERENT SHAPES
 * ─────────────────────────────────────────────────────────────────
 * The feature switches are independent booleans that each stand alone, so they
 * post one at a time and answer JSON with the page never reloading — the
 * InsightSettingsController idiom, and the reason its Stimulus controller puts
 * the previous segment back when a post fails.
 *
 * The two free-text fields belong to ONE save. They are read together when a
 * draft is built and are edited together, and a form that auto-submitted would
 * post a half-typed sentence on every keystroke that moved focus. So they get a
 * button, a redirect and a flash — the ClockController shape.
 *
 * The context depth is a single non-boolean choice, which is the third shape
 * this page already has elsewhere: a small form that submits on change and
 * redirects, exactly like the clock picker and the compose-behaviour segments.
 *
 * EVERY WRITE RE-ASKS THE INSTALLATION'S SWITCH
 * ─────────────────────────────────────────────
 * The rendered form is not the guarantee. An administrator can switch a feature
 * off while somebody has the page open, and app:test:ai-writing-help proves the
 * admin form is not the only writer of that switch either — so a POST that
 * would turn on something the installation has off is refused rather than
 * stored, and nothing is written.
 */
#[Route('/settings/ai', name: 'app_settings_ai_')]
#[IsGranted('ROLE_USER')]
final class AiPreferencesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AiAssistant            $ai,
        private readonly TranslatorInterface    $translator,
    ) {
    }

    /**
     * Switch one feature on or off for the current user.
     *
     * The key is validated by AiFeature::tryFrom() rather than against a list
     * kept here: a second copy of those three strings is a copy that can drift
     * from the enum the workers read, and an unknown key is a bug in our own
     * page rather than something to write silently.
     */
    #[Route('/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        // Per-action and per-subject, never the shared `ajax` id — one token
        // good for every action makes any one XSS worth all of them.
        if (false === $this->isCsrfTokenValid('ai_preferences_toggle', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();

        $feature = AiFeature::tryFrom($request->request->getString('key'));

        if (null === $feature) {
            return $this->json(['ok' => false, 'error' => 'unknown feature'], Response::HTTP_BAD_REQUEST);
        }

        $enabled = $request->request->getBoolean('enabled');

        if (true === $enabled && false === $this->ai->isEnabledFor($feature)) {
            // 409 and not 403, matching ComposeAssistController: nothing is
            // forbidden, the feature is simply not switched on for this
            // installation — and the page should stop offering it rather than
            // report an error the person can do nothing about.
            return $this->json(['ok' => false, 'error' => 'not available'], Response::HTTP_CONFLICT);
        }

        // Stored as OFF, so the column can never say yes to something the
        // installation says no to. See AiPreferences.
        $off = false === $enabled;

        match ($feature) {
            AiFeature::Search      => $user->aiPreferences->searchOff = $off,
            AiFeature::Categorise  => $user->aiPreferences->categoriseOff = $off,
            AiFeature::WritingHelp => $user->aiPreferences->writingHelpOff = $off,
        };

        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * The two notes the composer puts in front of the model.
     *
     * Clamped by the property hooks on the way in, and the template publishes
     * the same two numbers as `maxlength` — two copies of a limit is how a
     * textarea accepts four thousand characters and reports success while the
     * server stores six hundred, so both read AiPreferences' constants.
     *
     * No refusal for an over-long paste. The browser stops it at the maxlength
     * anyway, and somebody who got past that by other means should lose the
     * tail of their note rather than the whole of it.
     */
    #[Route('/notes', name: 'notes', methods: ['POST'])]
    public function notes(Request $request): Response
    {
        if (false === $this->isCsrfTokenValid('settings-ai-notes', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();

        $user->aiPreferences->aboutMe      = (string) $request->request->get('aboutMe', '');
        $user->aiPreferences->systemPrompt = (string) $request->request->get('systemPrompt', '');

        $this->entityManager->flush();

        // Translated HERE and not in the template: the flash bag holds
        // sentences rather than keys, because the controllers that fill it
        // interpolate names and counts into them — see the toast region in
        // _layout/app.html.twig.
        $this->addFlash('success', $this->translator->trans('settings.ai.notes.saved'));

        return $this->redirectToRoute('app_settings_index', ['section' => 'ai']);
    }

    /**
     * How much of a conversation the composer hands over.
     *
     * Redirects rather than answering with a fragment, for the reason the clock
     * picker beside it does: the choice matters on the next draft, not on this
     * page, so there is nothing here worth swapping.
     */
    #[Route('/context', name: 'context', methods: ['POST'])]
    public function context(Request $request): Response
    {
        if (false === $this->isCsrfTokenValid('settings-ai-context', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();

        $depth = ReplyContext::tryFrom((string) $request->request->get('depth', ''));

        if (null === $depth) {
            // A 404 rather than a silent default, the shape ClockController
            // uses for the same situation: the only caller is our own form, so
            // a value outside the enum is a bug and should say so.
            throw $this->createNotFoundException('Unknown reply context depth.');
        }

        $user->aiPreferences->replyContext = $depth;

        $this->entityManager->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'ai']);
    }
}
