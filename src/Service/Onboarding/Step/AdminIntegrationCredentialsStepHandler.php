<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

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
 * The file and photo services everyone here can attach from.
 *
 * Every implemented provider is offered, not only the OAuth ones. Nextcloud and
 * Immich need no app registration — each user signs in with their own app
 * password — but an admin still has to switch them on, and may want to pin one
 * server address for everybody, which is exactly what their form asks for.
 *
 * "Configured" therefore means a saved row rather than a client id: for a
 * self-hosted provider there is no client id to look for, and the admin's
 * decision to enable or leave it off is the thing that has been made.
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

        return true;
    }

    public function isSatisfied(User $user): bool
    {
        // Every provider has been either set up or deliberately left off.
        return [] === $this->undecided();
    }

    public function failureMessage(User $user): ?string
    {
        // Nothing here can be verified without the user making a round trip of
        // their own: credentials prove nothing until somebody consents with
        // them, and a name cannot be wrong.
        return null;
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
        return [
            'provider'       => $this->provider($request),
            'config'         => $this->config($request),
            // Every provider, always, and in the same order. The list used to
            // be the undecided ones, which meant the provider you had just
            // been editing vanished the moment you switched away from it —
            // switching saves, and a saved provider counted as decided.
            'providers'      => Provider::implemented(),
            'decided'        => $this->decided(),
            'aside_template' => 'onboarding/steps/_admin_integrations_aside.html.twig',
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $this->configWriter->saveIntegrationProvider($form->getData(), $form);
    }

    /**
     * Which providers the admin has actually said something about, keyed by
     * provider value.
     *
     * A saved row is not enough on its own: switching provider saves the form
     * on the way past, so an untouched provider ends up with an empty row.
     * "Decided" means switched on, or given an address, or given credentials.
     *
     * @return array<string, bool>
     */
    private function decided(): array
    {
        $decided = [];

        foreach ($this->configs->findAllIndexedByProvider() as $value => $config) {
            $decided[$value] = true === $config->isEnabled
                || '' !== (string) $config->baseUrl
                || '' !== (string) $config->clientId;
        }

        return $decided;
    }

    /**
     * @return list<Provider>
     */
    private function undecided(): array
    {
        $decided = $this->decided();

        return array_values(array_filter(
            Provider::implemented(),
            static fn (Provider $provider): bool => true !== ($decided[$provider->value] ?? false),
        ));
    }

    private function provider(Request $request): Provider
    {
        $requested = Provider::tryFrom((string) $request->query->get('provider'));

        // Any implemented provider, not only an undecided one: an admin has to
        // be able to go back and correct one they have already saved.
        if (null !== $requested && true === $requested->isImplemented()) {
            return $requested;
        }

        return $this->undecided()[0] ?? Provider::implemented()[0];
    }

    private function config(Request $request): IntegrationProviderConfig
    {
        $provider = $this->provider($request);

        return $this->configs->findOneByProvider($provider) ?? new IntegrationProviderConfig($provider);
    }
}
