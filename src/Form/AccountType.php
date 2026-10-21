<?php

namespace App\Form;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Enum\Account\Pop3DeleteMode;
use App\Domain\Helper\MailServerHost;
use App\Entity\Mail\Account;
use App\Service\Mail\MailPresetProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
            ->add('password', SecretType::class, [
                'attr' => [
                    'placeholder' => '••••••••',
                    'class' => 'form-input',
                ],
                'label' => 'Password',
                'required' => $options['require_password'],
            ])
            // ── Which protocol the incoming half speaks ────────────────────
            // Above the host fields, because it decides what they are called
            // and what port they start on. Not mapped to a setting but to the
            // column itself: this is what every syncer branches on.
            //
            // The choices are a plain radio pair rather than a dropdown. There
            // are two, they are mutually exclusive, and the one that is not
            // the default is the whole reason somebody opens this form.
            ->add('incomingProtocol', ChoiceType::class, [
                'choices'  => [
                    'account.form.protocol.imap' => IncomingProtocol::Imap,
                    'account.form.protocol.pop3' => IncomingProtocol::Pop3,
                ],
                'choice_value' => static fn (?IncomingProtocol $protocol): string => $protocol->value ?? '',
                'expanded'     => true,
                'multiple'     => false,
                'required'     => true,
                // No radio checked and none submitted reads as IMAP, which is
                // what the column's default says an account without an answer
                // is. A string, because ChoiceType casts empty_data to one
                // before matching it against the choice values.
                'empty_data'   => IncomingProtocol::Imap->value,
                'label'        => 'account.form.protocol.label',
                'attr'         => [
                    'class'                             => 'form-radio-group',
                    'data-settings--imap-preset-target' => 'protocol',
                    'data-action'                       => 'change->settings--imap-preset#protocolChanged',
                ],
            ])
            ->add('pop3DeleteMode', ChoiceType::class, [
                // Rendered only for a POP3 account; the template hides it for
                // an IMAP one. Kept on the form either way so a submitted form
                // — which includes hidden fields — round-trips without
                // resetting a choice the user made before switching protocol.
                'choices' => [
                    'account.form.pop3.delete_on_fetch' => Pop3DeleteMode::Delete,
                    'account.form.pop3.keep_on_server'  => Pop3DeleteMode::Keep,
                ],
                'choice_value' => static fn (?Pop3DeleteMode $mode): string => $mode->value ?? '',
                'mapped'       => false,
                'required'     => false,
                'label'        => 'account.form.pop3.delete_label',
                'help'         => 'account.form.pop3.delete_help',
                'attr'         => ['class' => 'form-select'],
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
                    // Both ports, because the placeholder has to say the same
                    // thing whichever protocol is chosen; the Stimulus
                    // controller swaps it when the radio changes. A POP3
                    // server that wants 110 is typed in, not offered.
                    'placeholder' => '993 / 995',
                    'class'       => 'form-input',
                ],
                'label' => 'account.form.imap.port',
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
            ->add('smtpPassword', SecretType::class, [
                'attr' => [
                    'placeholder'  => '••••••••',
                    'class'        => 'form-input',
                ],
                'label'        => 'account.form.smtp.password',
                'required'     => false,
            ]);

        // ── The POP3 delete mode: a form field, a jsonb setting ────────────
        // Not a column, so it cannot be mapped. The value lives in the
        // account's settings bag behind Account::$pop3DeleteMode, and this is
        // the one writer. Filled from the entity on the way in and written
        // back on the way out, so the form and the bag never disagree about
        // which of the two the user last chose.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $account = $event->getData();

            if ($account instanceof Account) {
                $event->getForm()->get('pop3DeleteMode')->setData($account->pop3DeleteMode);
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $account = $event->getData();

            if (false === $account instanceof Account) {
                return;
            }

            $chosen = $event->getForm()->get('pop3DeleteMode')->getData();

            // Unrecognised reads as Keep, which is Pop3DeleteMode's own rule
            // and not repeated here.
            $account->pop3DeleteMode = Pop3DeleteMode::fromSetting($chosen);
        });

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
