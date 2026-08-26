<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use App\Service\Onboarding\OnboardingFlow;
use App\Service\Onboarding\OnboardingStepRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The setup wizard.
 *
 * Four actions and no knowledge of any individual step: what a step contains,
 * whether it applies and what saving it means all live in its handler. Adding a
 * step never touches this class.
 *
 * IS_AUTHENTICATED rather than IS_AUTHENTICATED_FULLY — the latter excludes a
 * session restored from a remember-me cookie, which is most returning users.
 *
 * Everything renders into the shared `#modal` turbo-frame. Two consequences run
 * through the whole flow: every form must carry an explicit action (a form in a
 * frame posts to the document URL otherwise), and the wizard body sits inside
 * `[data-ui--modal-keep-open]` so submitting a step advances it instead of
 * closing the dialog.
 */
#[Route('/onboarding', name: 'app_onboarding_')]
#[IsGranted('IS_AUTHENTICATED')]
final class OnboardingController extends AbstractController
{
    /**
     * Every step value, so /{step} cannot swallow /finish.
     *
     * Spelled out rather than derived from OnboardingStep: a route requirement
     * is an attribute argument, so it has to be a constant expression. That
     * makes it the one place adding a step does not update itself — a new case
     * 404s here and, worse, takes the whole wizard down with it, because the
     * progress rail generates a URL for every applicable step. Adding a case
     * without adding it here is what OnboardingStepCoverageTest now checks.
     */
    public const string STEP_PATTERN = 'admin-mail|admin-integrations|admin-ai|account|profile|security|appearance|integrations';

    public function __construct(
        private readonly OnboardingFlow $flow,
        private readonly OnboardingStepRegistry $registry,
    ) {
    }

    /**
     * Where the wizard opens. Pure — no state is written here, which is what
     * lets "run setup again" be a plain link to it.
     */
    #[Route('', name: 'start', methods: ['GET'])]
    public function start(#[CurrentUser] User $user): Response
    {
        $step = $this->flow->firstStep($user);

        if (null === $step) {
            return $this->finish($user);
        }

        return $this->redirectToRoute('app_onboarding_step', ['step' => $step->value]);
    }

    /**
     * Render a step, and take its submission. One action for both, so the step
     * a form posts to is the step it was rendered from.
     */
    // The requirement is not decoration: without it /{step} also matches
    // /onboarding/finish, which is declared below and would never be reached.
    #[Route('/{step}', name: 'step', methods: ['GET', 'POST'], requirements: ['step' => self::STEP_PATTERN])]
    public function step(
        Request $request,
        #[CurrentUser] User $user,
        #[MapEntity(disabled: true)] OnboardingStep $step,
    ): Response {
        $this->assertApplicable($user, $step);

        $handler = $this->registry->handlerFor($step);
        $form    = $handler->createForm($user, $request);

        // Written before rendering, not after submitting: a step that sends the
        // user off to an OAuth consent screen never gets to run its own
        // "remember where we were".
        $this->flow->rememberStep($user, $step);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $handler->persist($user, $form);

            // A step can ask to be re-rendered rather than advanced. The
            // credential steps do it when the admin switches provider, so what
            // they typed is saved instead of discarded; the account and
            // integration steps do it after adding one, so the result is
            // visible and another can follow. Either way the user is still
            // working on the step, so it is not marked done yet.
            $switchTo = (string) $request->request->get('switch_to', '');

            if ('' !== $switchTo) {
                return $this->redirectToRoute(
                    'app_onboarding_step',
                    ['step' => $step->value, 'provider' => $switchTo],
                    Response::HTTP_SEE_OTHER,
                );
            }

            // Jumped to another step from the progress rail. The step is
            // marked done because it was saved on the way past — it is being
            // left deliberately, not abandoned.
            $gotoStep = OnboardingStep::tryFrom((string) $request->request->get('goto_step', ''));

            if (null !== $gotoStep && $this->flow->isApplicable($user, $gotoStep)) {
                $this->flow->markStepDone($user, $step);

                return $this->redirectToRoute(
                    'app_onboarding_step',
                    ['step' => $gotoStep->value],
                    Response::HTTP_SEE_OTHER,
                );
            }

            if ('' !== (string) $request->request->get('stay_on_step', '')) {
                return $this->redirectToRoute(
                    'app_onboarding_step',
                    ['step' => $step->value],
                    Response::HTTP_SEE_OTHER,
                );
            }

            $this->flow->markStepDone($user, $step);

            return $this->advanceFrom($user, $step);
        }

        // Submitted, rejected, and a switch was asked for: the user clicked
        // another provider and is still looking at this one. Say so, or the
        // click reads as having done nothing.
        // Submitted, rejected, and it was on its way somewhere: the user
        // clicked another provider or another step and is still looking at this
        // one. Say so, or the click reads as having done nothing.
        $switchBlocked = $form->isSubmitted() && (
            '' !== (string) $request->request->get('switch_to', '')
            || '' !== (string) $request->request->get('goto_step', '')
        );

        return $this->renderStep(
            $user,
            $step,
            $form,
            $handler->template(),
            [...$handler->viewData($user, $request), 'switchBlocked' => $switchBlocked],
        );
    }

    #[Route('/{step}/skip', name: 'skip', methods: ['POST'], requirements: ['step' => self::STEP_PATTERN])]
    public function skip(
        Request $request,
        #[CurrentUser] User $user,
        #[MapEntity(disabled: true)] OnboardingStep $step,
    ): Response {
        $this->assertApplicable($user, $step);

        if (false === $this->isCsrfTokenValid('onboarding-skip-'.$step->value, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->flow->markSkipped($user, $step);

        return $this->advanceFrom($user, $step);
    }

    /**
     * The end of the wizard, however it was reached: the last step, "skip
     * setup", or closing the dialog. Nothing distinguishes them, because from
     * here the only difference is whether the user reopens it themselves.
     */
    #[Route('/finish', name: 'finish', methods: ['POST'])]
    public function finishAction(Request $request, #[CurrentUser] User $user): Response
    {
        if (false === $this->isCsrfTokenValid('onboarding-finish', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $this->finish($user);
    }

    private function advanceFrom(User $user, OnboardingStep $step): Response
    {
        $next = $this->flow->next($user, $step);

        if (null === $next) {
            return $this->finish($user);
        }

        // 303: the browser must follow with a GET, or Turbo re-posts the step.
        return $this->redirectToRoute('app_onboarding_step', ['step' => $next->value], Response::HTTP_SEE_OTHER);
    }

    private function finish(User $user): Response
    {
        $this->flow->markComplete($user);

        return $this->render('onboarding/_done.html.twig');
    }

    /**
     * A step that does not apply must be indistinguishable from one that does
     * not exist — a plain user typing /onboarding/admin-mail gets a 404, not a
     * hint that such a page is out there.
     */
    private function assertApplicable(User $user, OnboardingStep $step): void
    {
        if (false === $this->flow->isApplicable($user, $step)) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @param array<string, mixed> $viewData
     */
    private function renderStep(
        User $user,
        OnboardingStep $step,
        FormInterface $form,
        string $template,
        array $viewData,
    ): Response {
        // 422 by hand: AbstractController::render() sets it automatically only
        // when a FormInterface is among the parameters, and the shell is handed
        // a FormView. Without it a rejected step answers 200, which Turbo and
        // any test both read as success.
        $status = $form->isSubmitted() && false === $form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('onboarding/_wizard.html.twig', [
            ...$viewData,
            'step'     => $step,
            'steps'    => $this->flow->applicableSteps($user),
            'progress' => $this->flow->progress($user, $step),
            'previous' => $this->flow->previous($user, $step),
            'isLast'   => null === $this->flow->next($user, $step),
            'statuses' => $this->flow->stepStatuses($user),
            'template' => $template,
            'form'     => $form->createView(),
        ], new Response(null, $status));
    }
}
