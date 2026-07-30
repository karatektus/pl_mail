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

        return 0 === $this->configs->countComplete();
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
            'action'        => $this->urlGenerator->generate('app_onboarding_step', [
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
            'providers'      => MailProvider::cases(),
            'aside_template' => 'onboarding/steps/_admin_mail_aside.html.twig',
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $this->configWriter->saveMailProvider($form->getData(), $form);
    }

    private function provider(Request $request): MailProvider
    {
        return MailProvider::tryFrom((string) $request->query->get('provider')) ?? MailProvider::Google;
    }

    private function config(Request $request): MailProviderConfig
    {
        $provider = $this->provider($request);

        return $this->configs->findOneByProvider($provider) ?? new MailProviderConfig($provider);
    }
}
