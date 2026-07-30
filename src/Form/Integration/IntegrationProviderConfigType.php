<?php

declare(strict_types=1);

namespace App\Form\Integration;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Form\PasswordManagerIgnore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin setup for one integration provider.
 *
 * The client credentials appear only for OAuth providers: a self-hosted service
 * authenticates per user with an app password, so there is no app registration
 * to hold and the fields would be dead weight.
 *
 * clientSecret is unmapped and write-only for the same reason as everywhere
 * else — the form never renders the stored value, so blank has to mean "keep
 * it", and clearing is an explicit checkbox.
 */
final class IntegrationProviderConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $config = $options['data'];
        $provider = $options['integration_provider'];

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->assertRegistrationIsWhole(...));

        $builder
            ->add('isEnabled', CheckboxType::class, [
                'label'    => 'admin.integrations.field.enabled',
                'required' => false,
                'help'     => 'admin.integrations.field.enabled_help',
            ])
            ->add('baseUrl', UrlType::class, [
                'label'    => 'admin.integrations.field.base_url',
                'required' => false,
                'help'     => $provider->needsBaseUrl()
                    ? 'admin.integrations.field.base_url_help_selfhosted'
                    : 'admin.integrations.field.base_url_help_saas',
                'attr' => ['placeholder' => 'https://cloud.example.com'] + PasswordManagerIgnore::ATTR,
            ]);

        if (AuthKind::OAuth2 !== $provider->authKind()) {
            return;
        }

        $builder
            ->add('clientId', TextType::class, [
                'label'    => 'admin.integrations.field.client_id',
                'required' => false,
                'attr'     => ['autocomplete' => 'off'] + PasswordManagerIgnore::ATTR,
            ])
            ->add('clientSecret', PasswordType::class, [
                'label'    => 'admin.integrations.field.client_secret',
                'required' => false,
                'mapped'   => false,
                'attr'     => [
                    'autocomplete' => 'off',
                    'placeholder'  => $config instanceof IntegrationProviderConfig && $config->hasClientSecret()
                        ? 'admin.integrations.secret_unchanged'
                        : 'admin.integrations.secret_none',
                ] + PasswordManagerIgnore::ATTR,
            ]);

        if ($config instanceof IntegrationProviderConfig && true === $config->hasClientSecret()) {
            $builder->add('clearClientSecret', CheckboxType::class, [
                'label'    => 'admin.integrations.clear_secret',
                'required' => false,
                'mapped'   => false,
            ]);
        }
    }

    /**
     * Same rule as the mail registrations: half a client credential pair is
     * worse than none, because it looks configured until someone tries to
     * connect. App-password providers have no pair and are skipped.
     */
    private function assertRegistrationIsWhole(FormEvent $event): void
    {
        $form   = $event->getForm();
        $config = $form->getData();

        if (false === $form->has('clientSecret')) {
            return;
        }

        $clientId  = trim((string) $form->get('clientId')->getData());
        $submitted = trim((string) $form->get('clientSecret')->getData());
        $stored    = $config instanceof IntegrationProviderConfig && true === $config->hasClientSecret();
        $cleared   = $form->has('clearClientSecret') && true === $form->get('clearClientSecret')->getData();

        $hasSecret = '' !== $submitted || (true === $stored && false === $cleared);

        if ('' !== $clientId && false === $hasSecret) {
            $form->get('clientSecret')->addError(new FormError('admin.integrations.secret_missing'));
        }

        if ('' === $clientId && '' !== $submitted) {
            $form->get('clientId')->addError(new FormError('admin.integrations.client_id_missing'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => IntegrationProviderConfig::class])
            ->setRequired('integration_provider')
            ->setAllowedTypes('integration_provider', Provider::class);
    }
}
