<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\User\User;
use App\Form\PasswordManagerIgnore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Creating and editing a user, as an administrator.
 *
 * **The password field exists on create and not on edit**, and that asymmetry
 * is the whole security design of this form rather than an oversight.
 *
 * An administrator who can set an existing user's password can read that
 * user's mail: set it, sign in, done. That is the same objection
 * CONTRIBUTING.md raises against exposing 2FA removal to admins — "a second way
 * into every mailbox on the install, reachable with nothing but a stolen admin
 * session" — and it applies here for identical reasons. A *new* user has no
 * mail yet, so setting the initial password discloses nothing.
 *
 * Someone locked out is recovered from the console with `app:user:password`,
 * where physical or shell access to the host is already the trust boundary.
 * That is a deliberately higher bar than a browser session, and it is the
 * point. The command is named here because for a while it was not: this
 * docblock and two strings on screen described a recovery path that had never
 * been written, so the one honest reading of "not here" was "nowhere".
 *
 * Roles are a single "administrator" checkbox rather than a role list. ROLE_USER
 * is implied for everyone and ROLE_ADMIN is the only other role the application
 * defines (UserEntityModel::ROLES), so a multi-select would be a list of one
 * with room to invent roles that grant nothing.
 */
final class UserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'       => 'admin.users.field.email',
                'constraints' => [new NotBlank(), new Email()],
                'attr'        => ['autocomplete' => 'off'] + PasswordManagerIgnore::ATTR,
            ])
            ->add('nameFirst', TextType::class, [
                'label'       => 'admin.users.field.name_first',
                'constraints' => [new NotBlank()],
                'attr'        => ['autocomplete' => 'off'],
            ])
            ->add('nameLast', TextType::class, [
                'label'       => 'admin.users.field.name_last',
                'constraints' => [new NotBlank()],
                'attr'        => ['autocomplete' => 'off'],
            ]);

        if (true === $options['is_new']) {
            // Unmapped: the entity stores a hash, and what arrives here is a
            // plaintext password. The controller hashes it.
            $builder->add('plainPassword', PasswordType::class, [
                'label'       => 'admin.users.field.password',
                'help'        => 'admin.users.field.password_help',
                'mapped'      => false,
                'constraints' => [
                    new NotBlank(),
                    // The length floor is the only control available when the
                    // person choosing the password is not the person who will
                    // use it. Read from User::PASSWORD_MIN_LENGTH rather than
                    // written here: `app:user:password` and ChangePasswordType
                    // have to demand the same thing, and until the constant
                    // existed they agreed only by each carrying a copy of the
                    // number with a comment pointing at the others.
                    new Length(min: User::PASSWORD_MIN_LENGTH, minMessage: 'admin.users.password_too_short'),
                ],
                'attr' => ['autocomplete' => 'new-password'],
            ]);
        }

        $builder->add('isAdmin', CheckboxType::class, [
            'label'    => 'admin.users.field.is_admin',
            'help'     => 'admin.users.field.is_admin_help',
            'mapped'   => false,
            'required' => false,
            'data'     => in_array(User::ROLE_ADMIN, $options['data']?->getRoles() ?? [], true),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new'     => false,
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
