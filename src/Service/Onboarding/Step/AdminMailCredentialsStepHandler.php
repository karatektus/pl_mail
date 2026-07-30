<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\Integration\MailProviderConfig;
use App\Entity\User\User;
use App\Form\Integration\MailProviderConfigType;
use App\Repository\Integration\MailProviderConfigRepository;
use App\Service\Integration\ProviderConfigWriter;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Google and Microsoft app registrations that let anyone here sign a
 * mailbox in with OAuth.
 *
 * Install-wide rather than personal, so only an admin is offered it — and only
 * while nothing has been registered yet. That second half is how "the first
 * admin with rights" is expressed without a brittle "is this user number one"
 * query: a later admin simply has nothing left to configure and never sees the
 * step.
 *
 * One provider at a time, chosen with ?provider=. Two full credential forms
 * side by side is a wall, and most installs only ever want one.
 */
final readonly class AdminMailCredentialsStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private MailProviderConfigRepository $configs,
        private ProviderConfigWriter $configWriter,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::AdminMailCredentials;
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
        return [] === $this->unregistered();
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
        return 'onboarding/steps/_admin_mail.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        $provider = $this->provider($request);

        return $this->formFactory->create(MailProviderConfigType::class, $this->config($request), [
            'mail_provider' => $provider,
            'offer_inherit' => true,
            'action'        => $this->urlGenerator->generate('app_onboarding_step', [
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
            // Both, always, and in the same order — a chip that disappeared
            // when its provider was saved took the one being edited with it.
            'providers'      => MailProvider::cases(),
            'decided'        => $this->registered(),
            'aside_template' => 'onboarding/steps/_admin_mail_aside.html.twig',
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $config = $form->getData();

        $this->configWriter->saveMailProvider($config, $form);

        if (true === $form->has('inheritToIntegrations') && true === $form->get('inheritToIntegrations')->getData()) {
            $this->configWriter->inheritFromMailProvider($config);
        }
    }

    /**
     * Which providers already have a complete registration, keyed by value.
     *
     * @return array<string, bool>
     */
    private function registered(): array
    {
        $registered = [];

        foreach ($this->configs->findAllIndexedByProvider() as $value => $config) {
            $registered[$value] = $config->isComplete();
        }

        return $registered;
    }

    /**
     * @return list<MailProvider>
     */
    private function unregistered(): array
    {
        $registered = $this->registered();

        return array_values(array_filter(
            MailProvider::cases(),
            static fn (MailProvider $provider): bool => true !== ($registered[$provider->value] ?? false),
        ));
    }

    private function provider(Request $request): MailProvider
    {
        // Any provider, not only an unregistered one: an admin has to be able
        // to go back and correct one they have already saved.
        return MailProvider::tryFrom((string) $request->query->get('provider'))
            ?? $this->unregistered()[0]
            ?? MailProvider::Google;
    }

    private function config(Request $request): MailProviderConfig
    {
        $provider = $this->provider($request);

        return $this->configs->findOneByProvider($provider) ?? new MailProviderConfig($provider);
    }
}
