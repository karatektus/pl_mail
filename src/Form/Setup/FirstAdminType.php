<?php

declare(strict_types=1);

namespace App\Form\Setup;

use App\Domain\Enum\AppLocale;
use App\Entity\User\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

/**
 * The account that owns a fresh install.
 *
 * The password is unmapped and hashed by FirstAdminInstaller — a plaintext
 * password must never reach the entity, where a stray flush would write it to
 * the column verbatim.
 *
 * Repeated on purpose despite being one more thing to type: this is the only
 * account on the install and there is no password reset, so a typo here means
 * restoring from nothing.
 *
 * The public URL is here rather than in a settings page because Gmail and Graph
 * push subscriptions are built by a worker, which has no request to infer a
 * hostname from. It is prefilled with the address this page was reached on,
 * which on first run is almost always right.
 *
 * The locale is mapped: it is the administrator's own interface language from
 * here on, and choosing it is also how the rest of this page gets translated.
 */
final class FirstAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // First field on the page, because it changes the rest of it: the
            // selector reloads the form in the chosen language, and picking a
            // language after reading five English labels is too late.
            ->add('locale', ChoiceType::class, [
                'label'   => 'setup.install.field.locale',
                'choices' => array_combine(
                    array_map(static fn (AppLocale $l): string => $l->emoji().'  '.$l->nativeLabel(), AppLocale::cases()),
                    array_map(static fn (AppLocale $l): string => $l->value, AppLocale::cases()),
                ),
                'attr' => [
                    'data-controller' => 'setup--locale-switch',
                    'data-action'     => 'change->setup--locale-switch#reload',
                ],
            ])
            ->add('nameFirst', TextType::class, [
                'label'       => 'setup.install.field.name_first',
                'constraints' => [new NotBlank()],
                'attr'        => ['autocomplete' => 'given-name', 'autofocus' => true],
            ])
            ->add('nameLast', TextType::class, [
                'label'       => 'setup.install.field.name_last',
                'constraints' => [new NotBlank()],
                'attr'        => ['autocomplete' => 'family-name'],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'setup.install.field.email',
                'help'        => 'setup.install.field.email_help',
                'constraints' => [new NotBlank(), new Email()],
                'attr'        => ['autocomplete' => 'username'],
            ])
            ->add('publicUrl', UrlType::class, [
                'label'        => 'setup.install.field.public_url',
                'help'         => 'setup.install.field.public_url_help',
                'mapped'       => false,
                'data'         => $options['public_url_guess'],
                'default_protocol' => null,
                // requireTld: false, explicitly — it defaults to true. plMail on
                // a LAN is reached at https://plmail or https://localhost, and
                // the default rejects the value this field prefills itself with.
                'constraints'  => [new NotBlank(), new Url(requireTld: false)],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => [
                    'label' => 'setup.install.field.password',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'second_options'  => [
                    'label' => 'setup.install.field.password_repeat',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'setup.install.password_mismatch',
                'constraints'     => [
                    new NotBlank(),
                    new Length(min: 8, minMessage: 'setup.install.password_too_short'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => User::class, 'public_url_guess' => ''])
            ->setAllowedTypes('public_url_guess', 'string');
    }
}
