<?php

declare(strict_types=1);

namespace App\Form\User;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Changing your own password, from Settings → Security.
 *
 * Where a person changes their own password whenever they like. The only
 * other browser form that sets one is RequiredPasswordType, which opens only
 * when somebody else chose the current password — and neither lets anyone set
 * a password but their own: UserFormType refuses to let an administrator
 * change somebody else's, because an admin session would then be a
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
 * ## Neither password is posted
 *
 * Since passwords are stretched in the browser (App\Security\PasswordKdf),
 * the boxes on screen are for typing into and nothing else. The new password
 * is NewPasswordType's business. The current one is proven by `currentProof`,
 * which assets/controllers/auth/password_form_controller.js fills before the
 * form goes: the auth hash stretched with this person's own salt — or, for an
 * account still on a hash of the password itself, the password, which is the
 * one thing that hash can be checked against. Either way it is exactly the
 * string the stored hash was made from, so UserPassword checks it unchanged.
 *
 * The length floor is User::PASSWORD_MIN_LENGTH, enforced in the browser now
 * that the server never sees the password — see NewPasswordType.
 */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label'    => 'settings.password.field.current',
                'mapped'   => false,
                'required' => false,
                // The one field here a password manager SHOULD offer to fill:
                // it is the credential already saved for this site. Its
                // neighbours say `new-password` so the browser offers to
                // generate and then to update, which is what they are for.
                'attr' => [
                    'autocomplete' => 'current-password',
                    'data-auth--password-form-target' => 'currentPassword',
                ],
            ])
            ->add('currentProof', HiddenType::class, [
                'mapped'      => false,
                'attr'        => ['data-auth--password-form-target' => 'currentProof'],
                'constraints' => [
                    new NotBlank(message: 'settings.password.needs_script'),
                    new UserPassword(message: 'settings.password.current_wrong'),
                ],
            ])
            ->add('new', NewPasswordType::class, [
                'label'  => false,
                'mapped' => false,
            ])
            // JSON, user key id => that key wrapped under the new password.
            // Filled by the browser, which is the only side that can open the
            // keys; PasswordChanger refuses the change if one it could open is
            // missing. Empty for an account with no keyring.
            ->add('rewrappedKeys', HiddenType::class, [
                'mapped' => false,
                'attr'   => ['data-auth--password-form-target' => 'rewrappedKeys'],
            ]);
    }
}
