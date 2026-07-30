<?php

declare(strict_types=1);

namespace App\Form\Integration;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\MailProviderConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The OAuth app registration behind mail sign-in.
 *
 * Two fields are deliberately unmapped, and the controller applies them:
 *
 *   clientSecret is write-only. The form never renders the stored value, so an
 *   empty submission has to mean "keep it" rather than "clear it" — mapping it
 *   would wipe the secret every time an admin edited the tenant.
 *
 *   tenant lives in the settings bag rather than as a column, since only
 *   Microsoft has one.
 */
final class MailProviderConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $config = $options['data'];
        $provider = $options['mail_provider'];

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
                    'placeholder'  => $config instanceof MailProviderConfig && $config->hasClientSecret()
                        ? 'admin.integrations.secret_unchanged'
                        : 'admin.integrations.secret_none',
                ],
            ]);

        // Offered only when there is something to clear, so the option cannot
        // read as "tick this to remove the secret I have not set".
        if ($config instanceof MailProviderConfig && true === $config->hasClientSecret()) {
            $builder->add('clearClientSecret', CheckboxType::class, [
                'label'    => 'admin.integrations.clear_secret',
                'required' => false,
                'mapped'   => false,
            ]);
        }

        if (MailProvider::Google === $provider) {
            $builder
                ->add('pubsubTopic', TextType::class, [
                    'label'    => 'admin.integrations.field.pubsub_topic',
                    'required' => false,
                    'mapped'   => false,
                    'help'     => 'admin.integrations.field.pubsub_topic_help',
                    'data'     => $config instanceof MailProviderConfig ? $config->getPubsubTopic() : null,
                    'attr'     => ['placeholder' => 'projects/your-project-id/topics/gmail-push'],
                ])
                ->add('pushVerificationToken', PasswordType::class, [
                    'label'    => 'admin.integrations.field.push_token',
                    'required' => false,
                    'mapped'   => false,
                    'help'     => 'admin.integrations.field.push_token_help',
                    'attr'     => [
                        'autocomplete' => 'new-password',
                        'placeholder'  => $config instanceof MailProviderConfig && null !== $config->pushVerificationToken
                            ? 'admin.integrations.secret_unchanged'
                            : 'admin.integrations.secret_none',
                    ],
                ]);
        }

        if (MailProvider::Microsoft === $provider) {
            $builder->add('tenant', TextType::class, [
                'label'    => 'admin.integrations.field.tenant',
                'required' => false,
                'mapped'   => false,
                'help'     => 'admin.integrations.field.tenant_help',
                'data'     => $config instanceof MailProviderConfig ? $config->getTenant() : null,
                'attr'     => ['placeholder' => 'common'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => MailProviderConfig::class])
            ->setRequired('mail_provider')
            ->setAllowedTypes('mail_provider', MailProvider::class);
    }
}
