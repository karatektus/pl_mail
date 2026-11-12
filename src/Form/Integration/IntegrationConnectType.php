<?php

declare(strict_types=1);

namespace App\Form\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Form\PasswordManagerIgnore;
use App\Form\SecretType;
use App\Entity\Integration\Integration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A user connecting to an app-password service.
 *
 * Never reached for OAuth providers — those redirect to the service instead, so
 * there is nothing to fill in.
 *
 * Which fields exist is decided by the provider and by what the admin has
 * settled. The address is offered only when it is genuinely the user's to set:
 * a pinned base URL means the field is absent rather than disabled, and the
 * controller ignores a submitted value regardless, so a crafted POST cannot
 * point the connection somewhere else.
 */
final class IntegrationConnectType extends AbstractType
{
    /**
     * Providers that ask for a username beside the credential, and whether it
     * is required. Immich's API key identifies the user on its own; a
     * Nextcloud app password and an OpenCloud app token do not. A plain WebDAV
     * server (#547) may want one or not — FileBrowser Quantum takes an API
     * token as the password and ignores the username — so the field is there
     * and may be left blank.
     */
    private const array USERNAME_REQUIRED = [
        Provider::Nextcloud->value => true,
        Provider::OpenCloud->value => true,
        Provider::WebDav->value    => false,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $integration = $options['data'];
        $provider = $options['integration_provider'];
        $isNew = false === $integration instanceof Integration || null === $integration->id;

        $builder->add('name', TextType::class, [
            'label' => 'settings.integrations.field.name',
            'help'  => 'settings.integrations.field.name_help',
            'attr'  => ['maxlength' => 100] + PasswordManagerIgnore::ATTR,
        ]);

        if (true === $options['url_editable']) {
            // A plain WebDAV address is the folder itself, path and all, which
            // the usual bare-host example would hide.
            $isWebDav = Provider::WebDav === $provider;

            $builder->add('baseUrl', UrlType::class, [
                'label' => 'settings.integrations.field.base_url',
                'help'  => $isWebDav ? 'settings.integrations.field.base_url_help.webdav' : null,
                'attr'  => [
                    'placeholder' => $isWebDav ? 'https://files.example.com/dav/files/' : 'https://cloud.example.com',
                ] + PasswordManagerIgnore::ATTR,
            ]);
        }

        if (true === isset(self::USERNAME_REQUIRED[$provider->value])) {
            $builder->add('username', TextType::class, [
                'label'    => 'settings.integrations.field.username',
                'required' => self::USERNAME_REQUIRED[$provider->value],
                'attr'     => ['autocomplete' => 'off'] + PasswordManagerIgnore::ATTR,
            ]);
        }

        // Write-only, as on the admin forms: blank keeps the stored credential,
        // so renaming a connection does not require re-pasting an app password
        // the user may no longer have.
        $builder->add('secret', SecretType::class, [
            'label'    => 'settings.integrations.field.secret.'.$provider->value,
            'help'     => 'settings.integrations.field.secret_help.'.$provider->value,
            'required' => $isNew,
            'mapped'   => false,
            'attr'     => [
                'placeholder'  => $isNew ? '' : 'settings.integrations.field.secret_unchanged',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class'   => Integration::class,
                'url_editable' => false,
            ])
            ->setRequired('integration_provider')
            ->setAllowedTypes('integration_provider', Provider::class)
            ->setAllowedTypes('url_editable', 'bool');
    }
}
