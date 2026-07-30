<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Domain\Enum\Onboarding\OnboardingStep;
use App\Entity\User\User;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * One stop on the setup wizard.
 *
 * A handler owns everything about its step except where it sits in the order,
 * which is the enum's job. That keeps the controller to four actions with no
 * knowledge of what any particular step does — adding a step is a new class and
 * a new enum case, and nothing else changes.
 *
 * Implementations are collected automatically: the interface is autoconfigured
 * with `app.onboarding_step`, so a new one only has to exist.
 */
interface OnboardingStepHandlerInterface
{
    public function step(): OnboardingStep;

    /**
     * Whether this step makes any sense for this user — never whether they have
     * already done it.
     *
     * The two used to be one question, and every bug it caused had the same
     * shape: saving a step satisfied it, the step stopped applying, and the
     * redirect that came back from the save landed on a 404. A step that has
     * been dealt with stays visitable; there is nothing to protect, since the
     * forms never render a stored secret back to the browser.
     *
     * So this answers only: may they (an admin step for a plain user), and is
     * there anything here at all (integrations, with nothing an admin has
     * made connectable). Hand-typing the URL of an inapplicable step must 404,
     * and that is decided here rather than inline in a controller.
     */
    public function isApplicable(User $user): bool;

    /**
     * Whether the step has been dealt with, for the tick in the progress rail.
     *
     * Separate from isApplicable() on purpose. This may become true while the
     * step stays perfectly visitable — and it can be true for a user who never
     * saw the wizard, having done the thing in Settings instead.
     */
    public function isSatisfied(User $user): bool;

    /**
     * Why this step is not working, if it is not.
     *
     * A step can be filled in and still be wrong — a mistyped app password, a
     * server that refuses the connection — and saying "done" to that is worse
     * than saying nothing, because the user stops looking. The rail shows a
     * cross instead of a tick, and the step itself repeats the message when
     * they come back to it.
     *
     * Null for steps whose settings cannot be tested without a round trip the
     * user has to make themselves: an OAuth client id and secret prove nothing
     * until somebody consents with them.
     */
    public function failureMessage(User $user): ?string;

    /** Template rendering the step's body, inside the wizard shell. */
    public function template(): string;

    /**
     * The step's form. Always one, even for a step with no fields — it is what
     * the Next button submits, and making it optional would put an "is there a
     * form" branch around the body, the footer and every button in the shell.
     *
     * Must carry an explicit action: a form rendered into a turbo-frame posts
     * to the document URL otherwise, which here is whatever page the wizard was
     * opened over.
     */
    public function createForm(User $user, Request $request): FormInterface;

    /**
     * Anything else the template needs. Merged into the render parameters, so a
     * key here shadows nothing the shell provides.
     *
     * @return array<string, mixed>
     */
    public function viewData(User $user, Request $request): array;

    /** Apply a valid submission. A no-op for a step with nothing to save. */
    public function persist(User $user, FormInterface $form): void;
}
