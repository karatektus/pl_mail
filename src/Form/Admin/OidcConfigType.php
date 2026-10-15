<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Oidc\OidcConfig;
use App\Form\PasswordManagerIgnore;
use App\Form\SecretType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin → SSO: the OpenID Connect provider people sign in through.
 *
 * The client secret is write-only, the rule MailProviderConfigType applies to
 * its own: the form never renders the stored value, so an empty submission
 * means "keep it", and clearing is a checkbox that only exists when there is
 * something to clear.
 *
 * **Whole or empty, nothing in between.** Issuer, client id and secret are one
 * registration. Two of the three is a setup somebody got halfway through, and a
 * saved half would look configured on this page while sign-in quietly went on
 * using the environment — OidcSettings ignores a row that is not whole. So a
 * half is refused here, where the person who can finish it is looking. All
 * three empty is a legitimate answer: it hands the decision back to the
 * OIDC_* variables.
 *
 * **The issuer has to be https**, refused at the field rather than at the first
 * sign-in. The ID token is believed on the strength of the TLS connection it
 * arrives over (IdTokenValidator), so OidcDiscovery refuses anything else — and
 * a refusal there shows as "single sign-on is not working" to whoever presses
 * the button, with the reason only in the log.
 */
final class OidcConfigType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $config    = $options['data'];
        $hasSecret = $config instanceof OidcConfig && true === $config->hasClientSecret();

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->assertTheRegistrationIsWhole(...));

        $builder
            ->add('issuer', TextType::class, [
                'label'       => 'admin.sso.field.issuer',
                'help'        => 'admin.sso.field.issuer_help',
                'required'    => false,
                'constraints' => [new Length(max: 255)],
                'attr'        => ['placeholder' => 'https://auth.example.com/application/o/plmail/', 'autocomplete' => 'off'] + PasswordManagerIgnore::ATTR,
            ])
            ->add('clientId', TextType::class, [
                'label'       => 'admin.sso.field.client_id',
                'required'    => false,
                'constraints' => [new Length(max: 255)],
                'attr'        => ['autocomplete' => 'off'] + PasswordManagerIgnore::ATTR,
            ])
            ->add('clientSecret', SecretType::class, [
                'label'    => 'admin.sso.field.client_secret',
                'required' => false,
                'mapped'   => false,
                'attr'     => [
                    'placeholder'  => true === $hasSecret ? 'admin.integrations.secret_unchanged' : 'admin.integrations.secret_none',
                ],
            ]);

        // Offered only when there is something to clear, so the option cannot
        // read as "tick this to remove the secret I have not set".
        if (true === $hasSecret) {
            $builder->add('clearClientSecret', CheckboxType::class, [
                'label'    => 'admin.sso.clear_secret',
                'help'     => 'admin.sso.clear_secret_help',
                'required' => false,
                'mapped'   => false,
            ]);
        }

        $builder
            ->add('buttonLabel', TextType::class, [
                'label'       => 'admin.sso.field.button_label',
                'help'        => 'admin.sso.field.button_label_help',
                'required'    => false,
                'constraints' => [new Length(max: 64)],
                'attr'        => ['placeholder' => 'Authentik'] + PasswordManagerIgnore::ATTR,
            ])
            ->add('scopes', TextType::class, [
                'label'       => 'admin.sso.field.scopes',
                'help'        => 'admin.sso.field.scopes_help',
                'required'    => false,
                'constraints' => [new Length(max: 255)],
                // No placeholder. It read "openid email profile", and the word
                // "email" in a placeholder is enough for Bitwarden to take the
                // field for the address of an identity and open its menu in
                // it — with `off` and its own opt-out attribute both set. The
                // help text below names the default instead.
                'attr'        => PasswordManagerIgnore::ATTR,
            ])
            ->add('autoCreate', CheckboxType::class, [
                'label'    => 'admin.sso.field.auto_create',
                'help'     => 'admin.sso.field.auto_create_help',
                'required' => false,
            ])
            ->add('requireVerifiedEmail', CheckboxType::class, [
                'label'    => 'admin.sso.field.require_verified_email',
                'help'     => 'admin.sso.field.require_verified_email_help',
                'required' => false,
            ]);
    }

    private function assertTheRegistrationIsWhole(FormEvent $event): void
    {
        $form   = $event->getForm();
        $config = $form->getData();

        $issuer    = trim((string) $form->get('issuer')->getData());
        $clientId  = trim((string) $form->get('clientId')->getData());
        $submitted = trim((string) $form->get('clientSecret')->getData());
        $stored    = $config instanceof OidcConfig && true === $config->hasClientSecret();
        $cleared   = $form->has('clearClientSecret') && true === $form->get('clearClientSecret')->getData();
        $hasSecret = '' !== $submitted || (true === $stored && false === $cleared);

        if ('' !== $issuer && false === str_starts_with($issuer, 'https://')) {
            $form->get('issuer')->addError($this->refusal('admin.sso.error.issuer_not_https'));
        }

        // All three, or none of them.
        if ('' === $issuer && '' === $clientId && false === $hasSecret) {
            return;
        }

        if ('' === $issuer) {
            $form->get('issuer')->addError($this->refusal('admin.sso.error.issuer_missing'));
        }

        if ('' === $clientId) {
            $form->get('clientId')->addError($this->refusal('admin.sso.error.client_id_missing'));
        }

        if (false === $hasSecret) {
            $form->get('clientSecret')->addError($this->refusal('admin.sso.error.secret_missing'));
        }
    }

    /**
     * Translated here rather than left as a key: a message added to a form by
     * hand is rendered verbatim, and only constraint violations arrive
     * translated — CalDavConnectType records the same. The catalogue entry
     * lives in the `validators` domain, beside the constraint messages.
     */
    private function refusal(string $key): FormError
    {
        return new FormError($this->translator->trans($key, [], 'validators'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OidcConfig::class]);
    }
}
