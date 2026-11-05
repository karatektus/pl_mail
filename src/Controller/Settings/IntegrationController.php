<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Integration\ServiceKind;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Form\Integration\IntegrationConnectType;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Calendar\Subscription\CalendarSubscriber;
use App\Domain\Exception\IntegrationException;
use App\Service\Integration\BrowserLogin\BrowserLoginService;
use App\Service\Integration\IntegrationConnector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    use ChecksCsrf;

    /** The token the browser-approval button sends with both of its calls. */
    public const string BROWSER_LOGIN_CSRF = 'integration-browser-login';

    public function __construct(
        private readonly IntegrationRepository               $integrationRepository,
        private readonly IntegrationProviderConfigRepository $configRepository,
        private readonly IntegrationConnector                $connector,
        private readonly EntityManagerInterface              $em,
        private readonly CalendarSubscriber                  $calendarSubscriber,
        private readonly CalendarRepository                  $calendarRepository,
        private readonly AddressBookRepository               $addressBookRepository,
        private readonly BrowserLoginService                 $browserLogin,
    ) {
    }

    /** The list on its own, for the frame's src and for post-mutation reloads. */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('settings/integrations/_list_frame.html.twig', $this->listData());
    }

    #[Route('/connect/{provider}', name: 'connect', methods: ['GET', 'POST'])]
    public function connect(Provider $provider, Request $request): Response
    {
        $config = $this->connector->requireConnectable($provider);

        // OAuth providers have nothing to fill in — they bounce to the service.
        // The route exists so the button has one destination whatever the auth
        // kind is.
        if (AuthKind::OAuth2 === $provider->authKind()) {
            return $this->redirectToRoute('app_integration_oauth_connect', ['provider' => $provider->value]);
        }

        return $this->handleForm(
            $request,
            new Integration($this->user(), $provider, $provider->label()),
            $provider,
            $config,
            $this->generateUrl('app_settings_integrations_connect', ['provider' => $provider->value]),
        );
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Integration $integration, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $integration);

        return $this->handleForm(
            $request,
            $integration,
            $integration->provider,
            $this->configRepository->findOneByProvider($integration->provider),
            $this->generateUrl('app_settings_integrations_edit', ['id' => $integration->id]),
        );
    }

    /**
     * Re-probe an existing connection on demand, for when a service was down
     * or a credential was rotated at the other end.
     */
    #[Route('/{id}/test', name: 'test', methods: ['POST'])]
    public function test(Integration $integration, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $integration);

        $this->assertCsrf($request, 'settings-integration-'.$integration->id);

        $error = $this->connector->retest($integration);

        return $this->listStream(null === $error
            ? 'settings.integrations.test_ok'
            : 'settings.integrations.test_failed');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Integration $integration, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $integration);

        $this->assertCsrf($request, 'settings-integration-'.$integration->id);

        $integration->isActive = false === $integration->isActive;
        $this->em->flush();

        return $this->listStream($integration->isActive
            ? 'settings.integrations.enabled'
            : 'settings.integrations.disabled');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Integration $integration, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $integration);

        $this->assertCsrf($request, 'settings-integration-'.$integration->id);

        // A Nextcloud connection also carries the user's calendars (#158).
        // The cascade on calendar.integration_id would delete them on its own,
        // and with them the events that came from mail rather than from the
        // server; unsubscribeAll() moves those first and hands back any push
        // registration, exactly as disconnecting from Settings → Calendars does.
        if (true === $integration->provider->offersCalendars()) {
            $this->calendarSubscriber->unsubscribeAll($integration);
        }

        $this->em->remove($integration);
        $this->em->flush();

        return $this->listStream('settings.integrations.disconnected');
    }

    /**
     * Begin connecting by approving in the browser (#235).
     *
     * JSON in and out, from the button beside the manual fields: the name and
     * address are read from those fields as they stand, so a person who typed
     * them first does not type them twice. `integration` is the connection
     * being edited — or, from the setup wizard, the one it would edit.
     *
     * The answer carries the address to approve at and where to ask for the
     * outcome. The flow's own token never reaches the browser.
     */
    #[Route('/browser-login/{provider}/start', name: 'browser_login_start', methods: ['POST'])]
    public function browserLoginStart(Provider $provider, Request $request): JsonResponse
    {
        $this->assertCsrf($request, self::BROWSER_LOGIN_CSRF);

        $payload = $request->toArray();
        $existing = null;

        if (true === isset($payload['integration']) && '' !== (string) $payload['integration']) {
            $existing = $this->integrationRepository->find((int) $payload['integration']);

            if (null === $existing) {
                throw $this->createNotFoundException();
            }

            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $existing);
        }

        $baseUrl = isset($payload['baseUrl']) && is_string($payload['baseUrl']) ? $payload['baseUrl'] : null;

        try {
            $started = $this->browserLogin->start(
                $this->user(),
                $provider,
                $existing,
                is_string($payload['name'] ?? null) ? $payload['name'] : '',
                $baseUrl,
            );
        } catch (IntegrationException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'loginUrl' => $started['loginUrl'],
            'pollUrl'  => $this->generateUrl('app_settings_integrations_browser_login_poll', ['id' => $started['id']]),
        ]);
    }

    /**
     * Whether the person has approved yet; asked every couple of seconds.
     *
     * Once connected the answer also carries the same Turbo Stream a manual
     * save answers with — the refreshed list and its toast — so the settings
     * page ends up exactly where a typed app password would have left it.
     */
    #[Route('/browser-login/poll/{id}', name: 'browser_login_poll', requirements: ['id' => '[A-Za-z0-9_-]{16,64}'], methods: ['POST'])]
    public function browserLoginPoll(string $id, Request $request): JsonResponse
    {
        $this->assertCsrf($request, self::BROWSER_LOGIN_CSRF);

        $result = $this->browserLogin->poll($this->user(), $id);

        if (true === $result->isPending) {
            return new JsonResponse(['status' => 'pending']);
        }

        if (false === $result->isConnected()) {
            return new JsonResponse(['status' => 'failed', 'message' => $result->failure]);
        }

        return new JsonResponse([
            'status' => 'connected',
            'ok'     => null === $result->probeError,
            'stream' => $this->renderView('settings/integrations/_saved.stream.html.twig', [
                ...$this->listData(),
                'toastMessage' => null === $result->probeError
                    ? 'settings.integrations.saved'
                    : 'settings.integrations.saved_with_error',
            ]),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Render or handle the connect form.
     *
     * One path for a new and an existing connection: the differences are all
     * decided inside the form type from the provider, rather than duplicated
     * between two actions that then drift.
     */
    private function handleForm(
        Request $request,
        Integration $integration,
        Provider $provider,
        ?IntegrationProviderConfig $config,
        string $action,
    ): Response {
        // Explicit action: a form with none submits to the document URL, and
        // this renders inside a Turbo Frame, so the POST would land on the
        // settings page instead of here.
        $form = $this->createForm(IntegrationConnectType::class, $integration, [
            'integration_provider' => $provider,
            'url_editable'         => $this->connector->isUrlEditable($provider, $config),
            'action'               => $action,
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $error = $this->connector->save($integration, $form);

            return $this->listStream(null === $error
                ? 'settings.integrations.saved'
                : 'settings.integrations.saved_with_error');
        }

        return $this->render('settings/integrations/_form.html.twig', [
            'provider'     => $provider,
            'integration'  => null === $integration->id ? null : $integration,
            'config'       => $config,
            'form'         => $form,
            'browserLogin' => $this->browserLogin->supports($provider),
        ]);
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
            'providers'    => Provider::of(ServiceKind::Files),
            'configs'      => $this->configRepository->findAllIndexedByProvider(),
            // For the disconnect confirmation, which says how many calendars
            // and address books go with a Nextcloud connection.
            'calendarCounts' => $this->calendarRepository->countMirroredByIntegration($this->user()),
            'bookCounts'     => $this->addressBookRepository->countByIntegration($this->user()),
        ];
    }

    private function user(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
