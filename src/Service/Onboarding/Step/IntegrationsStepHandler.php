<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Form\Integration\IntegrationConnectType;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationConnector;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The user's own file and photo services.
 *
 * Offered only when an admin has actually registered something connectable — a
 * step listing nothing is worse than no step.
 *
 * The two kinds of provider behave differently here, because they have to:
 *
 *   OAuth ones bounce to the service's consent screen, which no dialog can
 *   contain, so those are links out. Where the user was comes back from the
 *   settings bag on the next page load.
 *
 *   App-password ones — Nextcloud, Immich — are a couple of fields, so they
 *   expand in place. Sending someone out to a settings page mid-setup, as this
 *   step first did, dropped them on a bare form with none of the wizard around
 *   it.
 *
 * Expanding is a frame navigation with `?connect=`, not a client-side toggle,
 * because the fields belong to the step's own form: HTML has no nested forms,
 * so a second one inside the wizard would not submit.
 */
final readonly class IntegrationsStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private IntegrationProviderConfigRepository $configs,
        private IntegrationRepository $integrations,
        private IntegrationConnector $connector,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::Integrations;
    }

    public function isApplicable(User $user): bool
    {
        // The one genuine gate left: with nothing an admin has made
        // connectable, this step would be an empty list.
        return [] !== $this->configs->findConnectable();
    }

    public function isSatisfied(User $user): bool
    {
        foreach ($this->integrations->findBy(['usr' => $user]) as $integration) {
            // One that answered when it was last tried. IntegrationConnector
            // probes on every save, so this is never older than the credentials.
            if (null === $integration->lastError) {
                return true;
            }
        }

        return false;
    }

    public function failureMessage(User $user): ?string
    {
        foreach ($this->integrations->findBy(['usr' => $user]) as $integration) {
            if (null !== $integration->lastError && '' !== $integration->lastError) {
                return sprintf('%s — %s', $integration->name, $integration->lastError);
            }
        }

        return null;
    }

    public function template(): string
    {
        return 'onboarding/steps/_integrations.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        $provider = $this->expanded($request);

        if (null === $provider) {
            return $this->formFactory->create(FormType::class, null, [
                'action' => $this->stepUrl(),
            ]);
        }

        $config = $this->connector->requireConnectable($provider);

        return $this->formFactory->create(
            IntegrationConnectType::class,
            new Integration($user, $provider, $provider->label()),
            [
                'integration_provider' => $provider,
                'url_editable'         => $this->connector->isUrlEditable($provider, $config),
                'action'               => $this->stepUrl($provider),
            ],
        );
    }

    public function viewData(User $user, Request $request): array
    {
        $connected = [];

        foreach ($this->integrations->findBy(['usr' => $user]) as $integration) {
            $connected[$integration->provider->value] = true;
        }

        $connectUrls = [];

        foreach (Provider::implemented() as $provider) {
            if (AuthKind::OAuth2 !== $provider->authKind()) {
                $connectUrls[$provider->value] = $this->stepUrl($provider);
            }
        }

        return [
            'connectable' => $this->configs->findConnectable(),
            'connected'   => $connected,
            'expanded'    => $this->expanded($request),
            'connectUrls' => $connectUrls,
            'closeUrl'    => $this->stepUrl(),
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $integration = $form->getData();

        // The form-less variant: nothing was being connected, Next just moves on.
        if (false === $integration instanceof Integration) {
            return;
        }

        $this->connector->save($integration, $form);
    }

    /**
     * The provider whose fields are open, if any.
     *
     * OAuth providers are never expanded — there is nothing to type — so a
     * hand-typed `?connect=googleDrive` falls back to the plain list rather
     * than rendering a form that cannot work.
     */
    private function expanded(Request $request): ?Provider
    {
        $provider = Provider::tryFrom((string) $request->query->get('connect', ''));

        if (null === $provider || AuthKind::OAuth2 === $provider->authKind()) {
            return null;
        }

        return $provider;
    }

    private function stepUrl(?Provider $provider = null): string
    {
        return $this->urlGenerator->generate('app_onboarding_step', array_filter([
            'step'    => $this->step()->value,
            'connect' => $provider?->value,
        ]));
    }
}
