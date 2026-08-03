<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Entity\Mail\Account;
use App\Form\AccountType;
use App\Domain\Enum\Account\MailProvider;
use App\Repository\Integration\MailProviderConfigRepository;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\AccountCreator;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Mailboxes.
 *
 * Always offered, and it does not disappear once an account exists. It used to:
 * adding one made the step inapplicable, so pressing Next dropped it out of the
 * flow mid-use, which reads as the wizard losing its place. People have more
 * than one mailbox, and the natural thing after adding the first is to add the
 * second.
 *
 * So the step lists what is connected and the form is an expandable "add one"
 * beneath it — opened by default while there is nothing to list, since on a
 * fresh install that is the only thing to do here. Saving stays on the step so
 * the new account appears in the list rather than vanishing into the next one.
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
        private CsrfTokenManagerInterface $csrfTokenManager,
        private MailProviderConfigRepository $mailConfigs,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::Account;
    }

    public function isApplicable(User $user): bool
    {
        return true;
    }

    public function isSatisfied(User $user): bool
    {
        foreach ($this->accounts->findForUserOrdered($user) as $account) {
            // One that actually connects. An account that saved cleanly and
            // cannot log in is not a step anybody finished.
            if (null === $account->getSetting(AccountCreator::SETTING_CONNECTION_ERROR)) {
                return true;
            }
        }

        return false;
    }

    public function failureMessage(User $user): ?string
    {
        foreach ($this->accounts->findForUserOrdered($user) as $account) {
            $error = $account->getSetting(AccountCreator::SETTING_CONNECTION_ERROR);

            if (is_string($error) && '' !== $error) {
                return sprintf('%s — %s', $account->email ?? $account->username, $error);
            }
        }

        return null;
    }

    public function template(): string
    {
        return 'onboarding/steps/_account.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        if (false === $this->isAdding($user, $request)) {
            // Nothing to fill in, so Next has an empty form to submit and the
            // step advances without demanding another account.
            return $this->formFactory->create(FormType::class, null, [
                'action' => $this->stepUrl(),
            ]);
        }

        return $this->formFactory->create(AccountType::class, $this->accountCreator->blank(), [
            'action' => $this->stepUrl(adding: true),
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        $adding = $this->isAdding($user, $request);

        return [
            'accounts'  => $this->accounts->findForUserOrdered($user),
            // Only the providers an admin has actually registered: an OAuth
            // button for a provider with no client id sends the user to a
            // Google error page.
            'oauthProviders' => $this->oauthProviders(),
            'adding'    => $adding,
            'addUrl'    => $this->stepUrl(adding: true),
            'closeUrl'  => $this->stepUrl(),
            'aside_template' => 'onboarding/steps/_account_aside.html.twig',
            // The same controllers the settings modals put on their <form>,
            // with the same values. Both address targets inside
            // account/_fields.html.twig, so they have to sit on an ancestor of
            // it — and the form element is the only one this step owns.
            //
            // The values are not optional extras: without a URL the test
            // controller posts to the document it is on, which here is this
            // wizard step, and the HTML that comes back fails to parse as the
            // JSON it expected.
            //
            // Attached only while the fields are on screen — with the add form
            // closed the step's form is a Next button and there is nothing for
            // either controller to address.
            'form_attr' => false === $adding ? [] : [
                'data-controller' => 'settings--imap-preset settings--connection-test',
                'data-settings--connection-test-url-value' => $this->urlGenerator->generate('app_account_test_connection'),
                'data-settings--connection-test-csrf-value' => $this->csrfTokenManager->getToken('account_test')->getValue(),
            ],
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        $account = $form->getData();

        // The form-less variant: Next on a step that was only listing.
        if (false === $account instanceof Account) {
            return;
        }

        $this->accountCreator->create($account, $user);
    }

    /**
     * Mail providers that can currently be signed in to.
     *
     * @return list<MailProvider>
     */
    private function oauthProviders(): array
    {
        $configured = [];

        foreach ($this->mailConfigs->findAllIndexedByProvider() as $value => $config) {
            if (true === $config->isComplete()) {
                $configured[] = MailProvider::from($value);
            }
        }

        return $configured;
    }

    /**
     * Whether the add form is open. Open by default while there is nothing to
     * list — on a fresh install, adding is the only thing this step is for.
     */
    private function isAdding(User $user, Request $request): bool
    {
        if ('' !== (string) $request->query->get('add', '')) {
            return true;
        }

        return 0 === $this->accounts->countForUser($user);
    }

    private function stepUrl(bool $adding = false): string
    {
        return $this->urlGenerator->generate('app_onboarding_step', array_filter([
            'step' => $this->step()->value,
            'add'  => $adding ? '1' : null,
        ]));
    }
}
