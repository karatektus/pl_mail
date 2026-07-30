<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Form\AccountType;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\AccountCreator;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The first mailbox.
 *
 * Offered only to someone with no account at all: this is the fresh-install
 * hole — no account means no compose, no sync, an empty app — and once it is
 * filled, Settings is the right place to add a second one, not a wizard.
 *
 * IMAP and SMTP by hand. Gmail and Outlook come in through the OAuth buttons on
 * the accounts page, and a consent screen would take the user out of the modal
 * mid-setup; the wizard points them there instead.
 */
final readonly class AccountStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private AccountRepository $accounts,
        private AccountCreator $accountCreator,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::Account;
    }

    public function isApplicable(User $user): bool
    {
        return 0 === $this->accounts->countForUser($user);
    }

    public function template(): string
    {
        return 'onboarding/steps/_account.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        return $this->formFactory->create(AccountType::class, $this->accountCreator->blank(), [
            'action' => $this->urlGenerator->generate('app_onboarding_step', ['step' => $this->step()->value]),
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        return ['aside_template' => 'onboarding/steps/_account_aside.html.twig'];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $this->accountCreator->create($form->getData(), $user);
    }
}
