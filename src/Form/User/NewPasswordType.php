<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Entity\User\User;
use App\Security\PasswordKdf;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A new password, as a browser sends it: stretched, never in the clear.
 *
 * Two halves that look like one field. `password` is what the person types —
 * twice, for the reason ChangePasswordType gives — and it is NEVER POSTED:
 * assets/controllers/auth/password_form_controller.js takes the name off both
 * boxes before the form goes. What is posted instead is the auth hash it
 * stretched from them, with the salt it picked and the iteration count it was
 * told to use. See App\Security\PasswordKdf.
 *
 * The boxes stay real form fields, rather than inputs written into the
 * template, so that labels, help text and the theme come from the same place as
 * every other form on the install — and so that a password manager sees two
 * `new-password` fields with their values still in them, which is what makes
 * it offer to save the new password rather than the hash.
 *
 * What the server can no longer check is the password: its length, and that
 * the two boxes agree, are the browser's to enforce, and it does before it
 * stretches anything. A person who bypasses that can only give themselves a
 * short password, which is no worse than what they could already have chosen.
 * The iteration count IS checked, because a lower one is a weaker hash for
 * whoever steals the table, and the person choosing it is not the one at risk.
 *
 * An empty auth hash is what a browser without JavaScript sends, and is told
 * so in one sentence rather than as three blank-field errors.
 */
final class NewPasswordType extends AbstractType
{
    public function __construct(
        private readonly PasswordKdf $kdf,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'required'        => false,
                'first_options'   => [
                    'label' => 'settings.password.field.new',
                    'help'  => 'settings.password.field.new_help',
                    'help_translation_parameters' => ['%count%' => User::PASSWORD_MIN_LENGTH],
                    'attr'  => [
                        'autocomplete' => 'new-password',
                        'minlength'    => User::PASSWORD_MIN_LENGTH,
                        'data-auth--password-form-target' => 'newPassword',
                    ],
                ],
                'second_options'  => [
                    'label' => 'settings.password.field.repeat',
                    'attr'  => [
                        'autocomplete' => 'new-password',
                        'data-auth--password-form-target' => 'newRepeat',
                    ],
                ],
            ])
            ->add('authHash', HiddenType::class, [
                'attr'        => ['data-auth--password-form-target' => 'newAuthHash'],
                'constraints' => [
                    new NotBlank(message: 'settings.password.needs_script'),
                    new Callback(function (mixed $value, ExecutionContextInterface $context): void {
                        if (true === is_string($value) && '' !== $value && false === $this->kdf->isAuthHash($value)) {
                            $context->addViolation('settings.password.malformed');
                        }
                    }),
                ],
            ])
            ->add('salt', HiddenType::class, [
                'attr'        => ['data-auth--password-form-target' => 'newSalt'],
                'constraints' => [
                    new Callback(function (mixed $value, ExecutionContextInterface $context): void {
                        if (true === is_string($value) && '' !== $value && false === $this->kdf->isSalt($value)) {
                            $context->addViolation('settings.password.malformed');
                        }
                    }),
                ],
            ])
            ->add('iterations', HiddenType::class, [
                // Rendered with the configured count, which is where the
                // browser reads it from: it stretches with what the server
                // says, never with a number of its own, and the constraint
                // below checks that the same number came back.
                'data'        => $this->kdf->iterations,
                'attr'        => ['data-auth--password-form-target' => 'newIterations'],
                'constraints' => [
                    // Compared as a string: a hidden field submits one, and
                    // EqualTo is ==, so "600000" and 600000 agree.
                    new EqualTo(value: $this->kdf->iterations, message: 'settings.password.malformed'),
                ],
            ]);
    }

    public function getBlockPrefix(): string
    {
        return 'new_password';
    }
}
