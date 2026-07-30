<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Form\Integration\IntegrationProviderConfigType;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\ProviderConfigWriter;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Credentials for the file and photo services everyone here can attach from.
 *
 * Same shape as the mail step, and offered on the same terms: admin only, and
 * only until something has been registered. Nextcloud and Immich need no app
 * registration at all — each user signs in with their own app password — so
 * they are not offered here.
 */
final readonly class AdminIntegrationCredentialsStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private IntegrationProviderConfigRepository $configs,
        private ProviderConfigWriter $configWriter,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::AdminIntegrationCredentials;
    }

    public function isApplicable(User $user): bool
    {
        if (false === in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return false;
        }

        return 0 === $this->configs->countComplete();
    }

    public function template(): string
    {
        return 'onboarding/steps/_admin_integrations.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        $provider = $this->provider($request);

        return $this->formFactory->create(IntegrationProviderConfigType::class, $this->config($request), [
            'integration_provider' => $provider,
            'action'               => $this->urlGenerator->generate('app_onboarding_step', [
                'step'     => $this->step()->value,
                'provider' => $provider->value,
            ]),
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        $provider = $this->provider($request);

        return [
            'provider'       => $provider,
            'config'         => $this->config($request),
            'providers'      => $this->offered(),
            'aside_template' => 'onboarding/steps/_admin_integrations_aside.html.twig',
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $this->configWriter->saveIntegrationProvider($form->getData(), $form);
    }

    /**
     * Only the providers that have an app registration to hold. Nextcloud and
     * Immich authenticate with a per-user app password, so there is nothing an
     * admin could enter for them here.
     *
     * @return list<Provider>
     */
    private function offered(): array
    {
        return array_values(array_filter(
            Provider::implemented(),
            static fn (Provider $provider): bool => AuthKind::OAuth2 === $provider->authKind(),
        ));
    }

    private function provider(Request $request): Provider
    {
        $requested = Provider::tryFrom((string) $request->query->get('provider'));
        $offered   = $this->offered();

        if (null !== $requested && in_array($requested, $offered, true)) {
            return $requested;
        }

        return $offered[0] ?? Provider::GoogleDrive;
    }

    private function config(Request $request): IntegrationProviderConfig
    {
        $provider = $this->provider($request);

        return $this->configs->findOneByProvider($provider) ?? new IntegrationProviderConfig($provider);
    }
}
