<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\DTO\Ai\AiProbe;
use App\Form\Admin\AiSettingsType;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function __construct(
        private readonly AiSettingsRepository   $settings,
        private readonly AiAssistant            $assistant,
        private readonly EntityManagerInterface $entityManager,
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

    private function form(object $settings): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(AiSettingsType::class, $settings, [
            'action' => $this->generateUrl('app_admin_ai_settings'),
        ]);
    }
}
