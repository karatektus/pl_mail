<?php

declare(strict_types=1);

namespace App\Form\Integration;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Entity\IntegrationProviderConfig;
use Symfony\Component\Form\AbstractType;
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
                'attr' => ['placeholder' => 'https://cloud.example.com'],
            ]);

        if (AuthKind::OAuth2 !== $provider->authKind()) {
            return;
        }

        $builder
            ->add('clientId', TextType::class, [
                'label'    => 'admin.integrations.field.client_id',
                'required' => false,
                'attr'     => ['autocomplete' => 'off'],
            ])
            ->add('clientSecret', PasswordType::class, [
                'label'    => 'admin.integrations.field.client_secret',
                'required' => false,
                'mapped'   => false,
                'attr'     => [
                    'autocomplete' => 'new-password',
                    'placeholder'  => $config instanceof IntegrationProviderConfig && $config->hasClientSecret()
                        ? 'admin.integrations.secret_unchanged'
                        : 'admin.integrations.secret_none',
                ],
            ]);

        if ($config instanceof IntegrationProviderConfig && true === $config->hasClientSecret()) {
            $builder->add('clearClientSecret', CheckboxType::class, [
                'label'    => 'admin.integrations.clear_secret',
                'required' => false,
                'mapped'   => false,
            ]);
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
