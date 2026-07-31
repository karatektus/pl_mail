<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Step;

use App\Controller\Settings\TwoFactorController;
use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Service\Onboarding\OnboardingStepHandlerInterface;
use App\Service\User\TwoFactor\QrCodeRenderer;
use App\Service\User\TwoFactor\TwoFactorEnrolment;
use Scheb\TwoFactorBundle\Security\TwoFactor\Validator\Constraints\UserTotpCode;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Offering two-factor authentication while the user is still setting up.
 *
 * Opt-in, and it stays opt-in — Next moves on without it, and nothing here
 * refuses to continue. The step exists because of *when* it asks: somebody who
 * has just connected the mailbox their password resets arrive at is the one
 * person most likely to act on being told that, and almost nobody goes looking
 * for a security tab afterwards.
 *
 * The enrolment writes through the same TwoFactorEnrolment service as
 * Settings → Security, so there is one place that stages a secret and one
 * place that confirms it. What differs is only the shape: this step cannot post
 * to the settings endpoint, because the code field has to live in the wizard's
 * own form. HTML has no nested forms — a second `<form>` inside the step body
 * is dropped by the browser, and its submit button then silently submits the
 * wizard instead, advancing the step without ever enrolling. See the same note
 * on AccountStepHandler, which learned it first.
 */
final readonly class SecurityStepHandler implements OnboardingStepHandlerInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private TwoFactorEnrolment $enrolment,
        private QrCodeRenderer $qrCodes,
        private RequestStack $requestStack,
    ) {
    }

    public function step(): OnboardingStep
    {
        return OnboardingStep::Security;
    }

    public function isApplicable(User $user): bool
    {
        return true;
    }

    public function isSatisfied(User $user): bool
    {
        return $user->isTotpAuthenticationEnabled();
    }

    public function failureMessage(User $user): ?string
    {
        // Declining is a valid answer, not a fault. Marking a skipped step with
        // a cross would be scolding the user for a choice the step offered.
        return null;
    }

    public function template(): string
    {
        return 'onboarding/steps/_security.html.twig';
    }

    public function createForm(User $user, Request $request): FormInterface
    {
        if (false === $this->isEnrolling($user, $request)) {
            // Nothing to fill in, so Next has an empty form to submit and the
            // step advances without demanding a code from somebody who is not
            // setting this up.
            return $this->formFactory->create(FormType::class, null, [
                'action' => $this->stepUrl(),
            ]);
        }

        $form = $this->formFactory->create(FormType::class, null, [
            'action' => $this->stepUrl('1'),
        ]);

        // scheb's own constraint, which checks the code against the secret
        // staged on the currently authenticated user. Validating here rather
        // than in persist() is what puts a wrong code back on the step with an
        // error instead of advancing the wizard past it.
        return $form->add('code', TextType::class, [
            'label'       => false,
            'mapped'      => false,
            'attr'        => [
                'autocomplete' => 'one-time-code',
                'inputmode'    => 'numeric',
                'placeholder'  => '000000',
                'autofocus'    => true,
            ],
            'constraints' => [new UserTotpCode()],
        ]);
    }

    public function viewData(User $user, Request $request): array
    {
        $enrolling = $this->isEnrolling($user, $request);

        // Read once, straight out of the flash bag, exactly as the settings
        // page does — see the note on TwoFactorController about why freshly
        // minted recovery codes travel this way.
        $codes = $request->getSession()->getFlashBag()->get(TwoFactorController::FLASH_BACKUP_CODES);

        return [
            'aside_template'     => 'onboarding/steps/_security_aside.html.twig',
            'twoFactorEnabled'   => $user->isTotpAuthenticationEnabled(),
            'twoFactorEnrolling' => $enrolling,
            'newBackupCodes'     => [] === $codes ? null : array_values((array) $codes),
            // Only when the panel is open — the QR is the secret in scannable
            // form. begin() reuses an unconfirmed secret, which matters here:
            // this method runs again on every rejected code, and minting a new
            // one would change the QR under a user who had already scanned it.
            'twoFactorQr'        => $enrolling ? $this->qrCodes->dataUri($this->enrolment->begin($user)) : null,
            'twoFactorSecret'    => $enrolling ? $user->getTotpSecret() : null,
            'enrolUrl'           => $this->stepUrl('1'),
            'cancelUrl'          => $this->stepUrl(),
        ];
    }

    public function persist(User $user, FormInterface $form): void
    {
        // The form-less variant: Next on a step nobody chose to enrol from.
        if (false === $form->has('code')) {
            return;
        }

        $codes = $this->enrolment->confirm($user, (string) $form->get('code')->getData());

        if (null === $codes) {
            // The constraint passed a moment ago, so this is the narrow race
            // where the code's window rolled over in between. Say so rather
            // than reporting success on an enrolment that did not happen.
            $this->flash('error', 'two_factor.flash.code_rejected');

            return;
        }

        $this->flash('success', 'two_factor.flash.enabled');

        // The step re-renders rather than advancing — its button submits
        // stay_on_step — so this is read back on the very next request. It is
        // the one and only chance to show the codes.
        $this->session()?->getFlashBag()->set(TwoFactorController::FLASH_BACKUP_CODES, $codes);
    }

    /**
     * Whether the enrolment panel is open. Never for somebody who already has
     * 2FA on, which is what stops a stale `?enrol=1` in the back button from
     * staging a fresh secret over a working one.
     */
    private function isEnrolling(User $user, Request $request): bool
    {
        return false === $user->isTotpAuthenticationEnabled()
            && '1' === (string) $request->query->get('enrol', '');
    }

    private function flash(string $type, string $message): void
    {
        $this->session()?->getFlashBag()->add($type, $message);
    }

    private function session(): ?Session
    {
        $session = $this->requestStack->getSession();

        return $session instanceof Session ? $session : null;
    }

    private function stepUrl(?string $enrol = null): string
    {
        return $this->urlGenerator->generate('app_onboarding_step', array_filter([
            'step'  => $this->step()->value,
            'enrol' => $enrol,
        ]));
    }
}
