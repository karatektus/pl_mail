<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Ai\AiProbe;
use App\Domain\Enum\Ai\MetricWindow;
use App\Form\Admin\AiSettingsType;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiPerformancePanel;
use App\Service\Ai\EmbeddingBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → AI: which model host this installation talks to, and what it may do.
 *
 * One action for GET and POST, as the rest of the admin area does it: a
 * rejected value has to re-render with what was typed, and a separate GET could
 * not.
 *
 * THE TEST BUTTON IS A SEPARATE ACTION, AND IT DOES NOT SAVE
 * ─────────────────────────────────────────────────────────
 * Testing an address that has already been stored is testing the wrong thing —
 * the moment the answer is useful is BEFORE committing to it. So the probe
 * takes the address out of the submitted form, asks that host, and renders the
 * result back into the same frame with the form untouched. Nothing is written
 * either way.
 *
 * It also ignores the master switch, because an administrator setting this up
 * has not turned it on yet and a test that says "disabled" is answering a
 * question nobody asked.
 */
#[Route('/admin/ai', name: 'app_admin_ai_')]
#[IsGranted('ROLE_ADMIN')]
final class AiSettingsController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly AiSettingsRepository   $settings,
        private readonly AiAssistant            $assistant,
        private readonly EntityManagerInterface $entityManager,
        private readonly AiPerformancePanel     $panel,
        private readonly EmbeddingBackfill      $backfill,
    ) {
    }

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $settings = $this->settings->currentOrDefault();

        // The action has to be explicit: a Symfony form with no action submits
        // to the DOCUMENT url, and this renders inside a Turbo Frame — so the
        // POST would go to /admin?section=ai and quietly do nothing. The trap
        // PushSettingsController and IntegrationProviderController both record.
        $form = $this->form($settings);
        $form->handleRequest($request);

        $saved = false;

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            // Unmapped, so an empty box leaves the stored token alone. Clearing
            // it needs its own gesture rather than being what happens whenever
            // somebody saves the page without retyping a credential.
            $token = (string) $form->get('apiToken')->getData();

            if ('' !== trim($token)) {
                $settings->apiToken = $token;
            }

            $this->entityManager->persist($settings);
            $this->entityManager->flush();

            // Re-created rather than reused: the token field is unmapped and
            // still holds what was typed, and re-rendering it would put a
            // credential back on screen after it had been stored.
            $form  = $this->form($settings);
            $saved = true;
        }

        return $this->render('admin/ai/_frame.html.twig', [
            'settings' => $settings,
            'form'     => $form,
            'saved'    => $saved,
            'probe'    => null,
        ]);
    }

    /**
     * Ask the host whether it is there, without saving anything.
     *
     * The address comes off the submitted form rather than out of the database
     * so this can answer for something that has not been committed to yet.
     */
    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $settings = $this->settings->currentOrDefault();

        $form = $this->form($settings);
        $form->handleRequest($request);

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('ai_settings');

        $typed = trim((string) ($submitted['baseUrl'] ?? ''));

        $probe = '' === $typed
            ? AiProbe::unreachable('no_host')
            : $this->assistant->probe($typed);

        return $this->render('admin/ai/_frame.html.twig', [
            'settings' => $settings,
            'form'     => $form,
            'saved'    => false,
            'probe'    => $probe,
            // So the template can say "the model you named is not on that host",
            // which is a completely different errand from "nothing answered".
            'wanted'   => array_values(array_filter([
                $submitted['chatModel'] ?? null,
                $submitted['embeddingModel'] ?? null,
            ])),
        ]);
    }

    /**
     * What the model host is doing, as JSON, for the panel to poll.
     *
     * PROXIED, BECAUSE THE BROWSER CANNOT ASK THE HOST ITSELF
     * ──────────────────────────────────────────────────────
     * The model host is a different origin, usually on an address only this
     * server can route to, and `connect-src 'self'` refuses it in production
     * regardless. So the page asks this endpoint and this endpoint asks the
     * host — with short timeouts, because it is polled; see AiPerformancePanel.
     *
     * WHY IT CARRIES RENDERED HTML AS WELL AS THE NUMBERS
     * ──────────────────────────────────────────────────
     * The panel has six states to tell apart — off, unreachable, nothing
     * resident, warm, partly on the CPU, no history yet — and every one of them
     * is a sentence in three languages. Building those in JavaScript would put
     * the copy somewhere the translator never looks, so the states stay in
     * Twig and the payload carries both: the structured reading for anything
     * that wants to reason about it, and the fragment the controller swaps in.
     *
     * A GET that changes nothing, so no CSRF token — the policy this project
     * applies everywhere.
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $snapshot = $this->panel->snapshot(MetricWindow::fromRequest($request->query->get('window')));

        return $this->json([
            ...$this->panel->payload($snapshot),
            'html' => $this->renderView('admin/ai/_performance.html.twig', ['panel' => $snapshot]),
        ]);
    }

    /**
     * Start, pause or resume the backfill.
     *
     * One action for the three, because they are one control: they share every
     * guard, they answer the same way, and the panel re-renders from the same
     * snapshot afterwards whichever was pressed. Three endpoints would have
     * been three places to forget the CSRF check.
     *
     * The outcome is a KEY, not a sentence — 'already_running', 'search_off',
     * 'resumes_itself' — translated by the template that shows it. The service
     * layer does not know what language anybody reads, which is the same rule
     * AiProbe follows.
     */
    #[Route('/backfill/{action}', name: 'backfill', methods: ['POST'], requirements: ['action' => 'start|pause|resume'])]
    public function backfill(string $action, Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        $outcome = match ($action) {
            'start'  => $this->backfill->start(),
            'pause'  => $this->backfill->pause(),
            'resume' => $this->backfill->resume(),
            // Unreachable: the route requirement is the same closed set. Spelled
            // out anyway so a future action added to the requirement cannot
            // silently fall through to "started".
            default  => EmbeddingBackfill::NOT_RUNNING,
        };

        // Re-read rather than predicted. A start that was refused because
        // another administrator got there first must render THEIR run, not the
        // one this request thought it was making.
        $snapshot = $this->panel->snapshot(MetricWindow::fromRequest($request->query->get('window')));

        return $this->json([
            ...$this->panel->payload($snapshot),
            'outcome' => $outcome,
            // The outcome goes into the FRAGMENT as well as the payload: a
            // refusal is a sentence, and sentences live in Twig where the
            // translator can find them.
            'html'    => $this->renderView('admin/ai/_performance.html.twig', [
                'panel'   => $snapshot,
                'outcome' => $outcome,
            ]),
        ]);
    }

    private function form(object $settings): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(AiSettingsType::class, $settings, [
            'action' => $this->generateUrl('app_admin_ai_settings'),
        ]);
    }
}
