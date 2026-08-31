<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Entity\User\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Changing your own password, from Settings → Security.
 *
 * The one place on this install where a password can be changed through a
 * browser, and it is deliberately the only one: UserFormType refuses to let an
 * administrator set somebody else's, because an admin session would then be a
 * way into that person's mailbox. That objection does not apply to the account
 * holder, who is already reading the mail — so the field that must not exist
 * there is exactly the field that has to exist here. Until it did, plMail had a
 * password nobody could ever change: no reset flow, no self-service form, and
 * `app:user:password` on a console most people using an install cannot reach.
 *
 * **The current password is not a formality.** It is what stops a borrowed
 * session locking the owner out — an unattended unlocked laptop is the whole
 * threat here, and it is the same one that makes TwoFactorController demand a
 * code before it will switch the second factor off. Checked by Symfony's
 * UserPassword constraint, which asks the hasher about the *token's* user, so
 * this form can only ever change the password of whoever is signed in. There is
 * no user id in it to tamper with, and no `data_class` either: a form bound to
 * the User entity is a form that can write to the User entity, and the only
 * thing this may change is a hash it never sees. Every field is unmapped and
 * the controller does the hashing.
 *
 * Repeated, for the reason FirstAdminType repeats its own: a typo does not
 * fail, it sets a password nobody knows, and the person it happens to is locked
 * out the moment their session ends.
 *
 * The floor is User::PASSWORD_MIN_LENGTH, the same number the admin create form
 * applies, read from the constant rather than written out again — see the
 * constant's docblock for why it stopped being a literal in two places.
 */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label'       => 'settings.password.field.current',
                'mapped'      => false,
                'constraints' => [
                    new NotBlank(message: 'settings.password.current_required'),
                    new UserPassword(message: 'settings.password.current_wrong'),
                ],
                // The one field here a password manager SHOULD offer to fill:
                // it is the credential already saved for this site. Its
                // neighbours say `new-password` so the browser offers to
                // generate and then to update, which is what they are for.
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => [
                    'label' => 'settings.password.field.new',
                    'help'  => 'settings.password.field.new_help',
                    // The floor is stated to the reader from the same constant
                    // the constraint enforces it with. A help string that spelt
                    // "12" out would be a second place to change, and the one
                    // that nothing fails when somebody forgets.
                    'help_translation_parameters' => ['%count%' => User::PASSWORD_MIN_LENGTH],
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'second_options'  => [
                    'label' => 'settings.password.field.repeat',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'settings.password.mismatch',
                'constraints'     => [
                    new NotBlank(),
                    new Length(
                        min: User::PASSWORD_MIN_LENGTH,
                        minMessage: 'settings.password.too_short',
                    ),
                ],
            ]);
    }
}
