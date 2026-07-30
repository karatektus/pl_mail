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
     * Whether this user should be offered this step at all.
     *
     * Answers two different questions at once, deliberately: may they (an admin
     * step for a plain user) and is there anything to do (an account step for
     * someone who already has one). Both come out as "do not show it", and
     * neither should be an inline check in a controller — hand-typing the URL
     * of an inapplicable step must 404, and that is decided here.
     */
    public function isApplicable(User $user): bool;

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
