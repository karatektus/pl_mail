<?php

namespace App\Form;

use App\Domain\Helper\MailServerHost;
use App\Entity\Mail\Account;
use App\Service\Mail\MailPresetProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
                    'class'                             => 'form-select',
                    'data-settings--imap-preset-target' => 'select',
                    'data-action'                       => 'change->settings--imap-preset#apply',
                    'data-presets'                      => json_encode($this->presetProvider->toClientArray(), JSON_THROW_ON_ERROR),
                ],
            ])
            ->add('email', TextType::class, [
                'attr' => [
                    'placeholder' => 'you@example.com',
                    'class' => 'form-input',
                ] + PasswordManagerIgnore::ATTR,
                'label' => 'Account label',
            ])
            ->add('username', TextType::class, [
                'attr' => [
                    'placeholder'                       => 'you@example.com',
                    'class'                             => 'form-input',
                    'autocomplete'                      => 'email',
                    'data-settings--imap-preset-target' => 'username',
                    'data-action'                       => 'change->settings--imap-preset#detect blur->settings--imap-preset#detect',
                ] + PasswordManagerIgnore::ATTR,
                'label'       => 'Email address',
                'constraints' => [new NotBlank()],
            ])
            ->add('password', PasswordType::class, [
                'attr' => [
                    'placeholder' => '••••••••',
                    'class' => 'form-input',
                    'autocomplete' => 'new-password',
                ] + PasswordManagerIgnore::SECRET,
                'label' => 'Password',
                'always_empty' => true,
                'required' => $options['require_password'],
            ])
            ->add('imapHost', TextType::class, [
                'attr' => [
                    'placeholder' => 'imap.example.com',
                    'class' => 'form-input',
                ] + PasswordManagerIgnore::ATTR,
                'label' => 'IMAP host',
                'constraints' => [new NotBlank(), self::hostConstraint()],
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
                ] + PasswordManagerIgnore::ATTR,
                'label' => 'SMTP host',
                'constraints' => [self::hostConstraint()],
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
            ])
            // ── A second password, for sending ─────────────────────────────
            // Two fields, and the first is the one that decides. A server that
            // wants a different password to send is rare, so the form shows
            // one password field and a button; the button ticks this box and
            // reveals the second field (see settings--smtp-password and
            // account/_fields.html.twig).
            //
            // The box is what is believed, not the field. A password manager
            // will fill a password input it can find whether or not it is on
            // screen, and a blank field on the edit form means "keep what is
            // stored" — so neither "has a value" nor "is blank" says whether
            // this account is meant to have a second password. The box does.
            ->add('separateSmtpPassword', CheckboxType::class, [
                'mapped'   => false,
                'required' => false,
                'label'    => 'account.form.smtp.separate',
            ])
            ->add('smtpPassword', PasswordType::class, [
                'attr' => [
                    'placeholder'  => '••••••••',
                    'class'        => 'form-input',
                    'autocomplete' => 'new-password',
                ] + PasswordManagerIgnore::SECRET,
                'label'        => 'account.form.smtp.password',
                'always_empty' => true,
                'required'     => false,
            ]);

        // What the account had before the form wrote on it: `always_empty`
        // submits a blank for an untouched field, and by POST_SUBMIT that
        // blank is already on the entity.
        $stored = null;

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event) use (&$stored): void {
            $account = $event->getData();
            $stored  = $account instanceof Account ? $account->smtpPassword : null;

            $event->getForm()->get('separateSmtpPassword')->setData(null !== $stored && '' !== $stored);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use (&$stored): void {
            $account = $event->getData();

            if (false === $account instanceof Account) {
                return;
            }

            // Box clear: one password for both, whatever the hidden field
            // was sent with.
            if (true !== $event->getForm()->get('separateSmtpPassword')->getData()) {
                $account->smtpPassword = null;

                return;
            }

            // Box ticked, field blank: keep the stored one — the same rule the
            // password above it follows on the edit form. On a new account
            // there is none, and the account simply has no second password.
            if (null === $account->smtpPassword || '' === $account->smtpPassword) {
                $account->smtpPassword = $stored;
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
            'require_password' => true,
        ]);

        $resolver->setAllowedTypes('require_password', 'bool');
    }

    /**
     * A hostname or an IP address, nothing that parses as more of a URL.
     *
     * The host is spliced into the mailer DSN and the IMAP socket address, so
     * `mail.example.com?verify_peer=0` was not a typo but a second option
     * string. See MailServerHost. Blank is NotBlank's business, or allowed.
     */
    private static function hostConstraint(): Callback
    {
        return new Callback(static function (?string $host, ExecutionContextInterface $context): void {
            if (null === $host || '' === $host || true === MailServerHost::isValid($host)) {
                return;
            }

            $context->buildViolation('account.host_invalid')->addViolation();
        });
    }
}
