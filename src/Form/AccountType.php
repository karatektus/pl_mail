<?php

namespace App\Form;

use App\Entity\Account;
use App\Service\Mail\MailPresetProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class AccountType extends AbstractType
{
    public function __construct(
        private readonly MailPresetProvider $presetProvider,
    )
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('preset', ChoiceType::class, [
                'mapped'      => false,
                'required'    => false,
                'label'       => 'account.form.preset.label',
                'placeholder' => 'account.form.preset.placeholder',
                'choices'     => $this->presetProvider->choices(),
                'autocomplete' => true,
                'attr' => [
                    'class'                    => 'form-select',
                    'data-imap-preset-target'  => 'select',
                    'data-action'              => 'change->imap-preset#apply',
                    'data-presets'             => json_encode($this->presetProvider->toClientArray(), JSON_THROW_ON_ERROR),
                ],
            ])
            ->add('email', TextType::class, [
                'attr' => [
                    'placeholder' => 'you@example.com',
                    'class' => 'form-input',
                ],
                'label' => 'Account label',
            ])
            ->add('username', TextType::class, [
                'attr' => [
                    'placeholder'             => 'you@example.com',
                    'class'                   => 'form-input',
                    'autocomplete'            => 'email',
                    'data-imap-preset-target' => 'username',
                    'data-action'             => 'change->imap-preset#detect blur->imap-preset#detect',
                ],
                'label'       => 'Email address',
                'constraints' => [new NotBlank()],
            ])
            ->add('password', PasswordType::class, [
                'attr' => [
                    'placeholder' => '••••••••',
                    'class' => 'form-input',
                    'autocomplete' => 'new-password',
                ],
                'label' => 'Password',
                'always_empty' => true,
                'required' => $options['require_password'],
            ])
            ->add('imapHost', TextType::class, [
                'attr' => [
                    'placeholder' => 'imap.example.com',
                    'class' => 'form-input',
                ],
                'label' => 'IMAP host',
                'constraints' => [new NotBlank()],
            ])
            ->add('imapPort', IntegerType::class, [
                'attr' => [
                    'placeholder' => '993',
                    'class' => 'form-input',
                ],
                'label' => 'IMAP port',
                'constraints' => [new NotBlank(), new Range(min: 1, max: 65535)],
            ])
            ->add('imapEncryption', ChoiceType::class, [
                'choices' => [
                    'SSL / TLS' => 'ssl',
                    'STARTTLS' => 'starttls',
                    'None' => 'none',
                ],
                'attr' => ['class' => 'form-select'],
                'label' => 'Encryption',
            ])
            ->add('smtpHost', TextType::class, [
                'required' => false,
                'attr' => [
                    'placeholder' => 'smtp.example.com',
                    'class' => 'form-input',
                ],
                'label' => 'SMTP host',
            ])
            ->add('smtpPort', IntegerType::class, [
                'required' => false,
                'attr' => [
                    'placeholder' => '587',
                    'class' => 'form-input',
                ],
                'label' => 'SMTP port',
                'constraints' => [new Range(min: 1, max: 65535)],
            ])
            ->add('smtpEncryption', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'STARTTLS' => 'starttls',
                    'SSL / TLS' => 'ssl',
                    'None' => 'none',
                ],
                'attr' => [
                    'class' => 'form-select',
                    ],
                'label' => 'SMTP encryption',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
            'require_password' => true,
        ]);

        $resolver->setAllowedTypes('require_password', 'bool');
    }
}
