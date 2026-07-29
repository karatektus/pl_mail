<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use App\Entity\IntegrationProviderConfig;
use App\Entity\User;
use App\Repository\IntegrationProviderConfigRepository;
use App\Repository\IntegrationRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use App\Service\Integration\IntegrationUrlValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A user's own connections to the services an admin has enabled.
 *
 * Only providers that are enabled, implemented and — for OAuth — credentialed
 * can be connected; findConnectable() is the single gate, so no route here can
 * accidentally offer a stub. Anything a user is not allowed to reach is
 * refused server-side rather than merely hidden in the template.
 *
 * Saving always re-probes the service. A connection that stores cleanly but
 * cannot actually list a folder is worse than a visible failure, because the
 * user only finds out mid-compose. The probe result lands on the entity, so
 * the list can say a connection has gone stale before it is next used.
 */
#[Route('/settings/integrations', name: 'app_settings_integrations_')]
#[IsGranted('IS_AUTHENTICATED')]
final class IntegrationController extends AbstractController
{
    public function __construct(
        private readonly IntegrationRepository               $integrationRepository,
        private readonly IntegrationProviderConfigRepository $configRepository,
        private readonly IntegrationDriverRegistry           $drivers,
        private readonly IntegrationUrlValidator             $urlValidator,
        private readonly EntityManagerInterface              $em,
    ) {
    }

    /** The list on its own, for the frame's src and for post-mutation reloads. */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('settings/integrations/_list_frame.html.twig', $this->listData());
    }

    #[Route('/connect/{provider}', name: 'connect', methods: ['GET'])]
    public function connect(Provider $provider): Response
    {
        $config = $this->assertConnectable($provider);

        // OAuth providers do not have a form to fill in — they bounce to the
        // service. The route exists so the button has one destination
        // regardless of auth kind.
        if (AuthKind::OAuth2 === $provider->authKind()) {
            return $this->redirectToRoute('app_integration_oauth_connect', ['provider' => $provider->value]);
        }

        return $this->render('settings/integrations/_form.html.twig', [
            'provider'    => $provider,
            'integration' => null,
            'urlEditable' => $this->urlValidator->isUserEditable($provider, $config),
            'config'      => $config,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'])]
    public function edit(Integration $integration): Response
    {
        $this->assertOwned($integration);

        $config = $this->configRepository->findOneByProvider($integration->provider);

        return $this->render('settings/integrations/_form.html.twig', [
            'provider'    => $integration->provider,
            'integration' => $integration,
            'urlEditable' => $this->urlValidator->isUserEditable($integration->provider, $config),
            'config'      => $config,
        ]);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (false === $this->isCsrfTokenValid('settings-integration', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $id = $request->request->getInt('id');
        $integration = 0 === $id ? null : $this->integrationRepository->findOneForUser($this->getUser(), $id);

        if (0 !== $id && null === $integration) {
            throw $this->createNotFoundException();
        }

        if (null === $integration) {
            $provider = Provider::tryFrom((string) $request->request->get('provider'));

            if (null === $provider) {
                throw $this->createNotFoundException();
            }

            $this->assertConnectable($provider);

            $integration = new Integration($this->user(), $provider, (string) $request->request->get('name', ''));
            $this->em->persist($integration);
        } else {
            $integration->name = (string) $request->request->get('name', '');
        }

        $config = $this->configRepository->findOneByProvider($integration->provider);

        if (true === $this->urlValidator->isUserEditable($integration->provider, $config)) {
            $integration->baseUrl = $this->nullIfBlank($request->request->get('baseUrl'));
        }

        $integration->username = $this->nullIfBlank($request->request->get('username'));

        // Blank keeps the stored credential, exactly as on the admin form: the
        // field never renders the secret, so blank cannot mean "clear it".
        $submittedSecret = $this->nullIfBlank($request->request->get('secret'));

        if (null !== $submittedSecret) {
            $integration->secret = $submittedSecret;
        }

        $error = $this->probe($integration);

        $this->em->flush();

        return $this->listStream(null === $error
            ? 'settings.integrations.saved'
            : 'settings.integrations.saved_with_error');
    }

    /**
     * Re-probe an existing connection on demand, for when a service was down
     * or a credential was rotated at the other end.
     */
    #[Route('/{id}/test', name: 'test', methods: ['POST'])]
    public function test(Integration $integration, Request $request): Response
    {
        $this->assertOwned($integration);

        if (false === $this->isCsrfTokenValid('settings-integration-'.$integration->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $error = $this->probe($integration);
        $this->em->flush();

        return $this->listStream(null === $error
            ? 'settings.integrations.test_ok'
            : 'settings.integrations.test_failed');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Integration $integration, Request $request): Response
    {
        $this->assertOwned($integration);

        if (false === $this->isCsrfTokenValid('settings-integration-'.$integration->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $integration->isActive = false === $integration->isActive;
        $this->em->flush();

        return $this->listStream($integration->isActive
            ? 'settings.integrations.enabled'
            : 'settings.integrations.disabled');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Integration $integration, Request $request): Response
    {
        $this->assertOwned($integration);

        if (false === $this->isCsrfTokenValid('settings-integration-'.$integration->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($integration);
        $this->em->flush();

        return $this->listStream('settings.integrations.disconnected');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Ask the service whether the credentials work, recording the outcome on
     * the entity either way. Returns the failure message, or null on success.
     */
    private function probe(Integration $integration): ?string
    {
        try {
            $this->drivers->forIntegration($integration)->verify($integration);
            $integration->recordSuccess();

            return null;
        } catch (IntegrationException $e) {
            $integration->recordFailure($e->getMessage());

            return $e->getMessage();
        }
    }

    private function listStream(string $toastMessage): Response
    {
        return $this->render('settings/integrations/_saved.stream.html.twig', [
            ...$this->listData(),
            'toastMessage' => $toastMessage,
        ], new Response(null, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    /**
     * @return array<string,mixed>
     */
    private function listData(): array
    {
        return [
            'integrations' => $this->integrationRepository->findForUserOrdered($this->getUser()),
            // Every connectable provider, including ones already connected.
            // A person can legitimately have two Nextclouds — home and work —
            // which is what the unique key on (usr, provider, name) allows and
            // what the name field on the form is for. Hiding the button after
            // the first connection would quietly contradict both.
            'available'    => $this->configRepository->findConnectable(),
            'providers'    => Provider::cases(),
            'configs'      => $this->configRepository->findAllIndexedByProvider(),
        ];
    }

    private function assertConnectable(Provider $provider): IntegrationProviderConfig
    {
        $config = $this->configRepository->findOneByProvider($provider);

        if (null === $config || false === $config->isConnectable()) {
            throw $this->createNotFoundException();
        }

        return $config;
    }

    private function assertOwned(Integration $integration): void
    {
        if ($integration->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function user(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
