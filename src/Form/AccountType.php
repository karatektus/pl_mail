<?php

namespace App\Form;

use App\Entity\Account;
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
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'attr' => [
                    'placeholder' => 'Work, Personal…',
                    'class' => 'form-input',
                ],
                'label' => 'Account label',
            ])
            ->add('username', TextType::class, [
                'attr' => [
                    'placeholder' => 'you@example.com',
                    'class' => 'form-input',
                    'autocomplete' => 'email',
                ],
                'label' => 'Email address',
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
                'data' => 993,
                'constraints' => [new NotBlank(), new Range(min: 1, max: 65535)],
            ])
            ->add('imapEncryption', ChoiceType::class, [
                'choices' => [
                    'SSL / TLS' => 'ssl',
                    'STARTTLS'  => 'starttls',
                    'None'      => 'none',
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
                'data' => 587,
                'constraints' => [new Range(min: 1, max: 65535)],
            ])
            ->add('smtpEncryption', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'STARTTLS'  => 'starttls',
                    'SSL / TLS' => 'ssl',
                    'None'      => 'none',
                ],
                'attr' => ['class' => 'form-select'],
                'label' => 'SMTP encryption',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
        ]);
    }
}
