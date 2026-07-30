<?php

declare(strict_types=1);

namespace App\Form\Integration;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Integration\MailProviderConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->assertRegistrationIsWhole(...));

        $builder
            ->add('clientId', TextType::class, [
                'label'    => 'admin.integrations.field.client_id',
                'required' => false,
                'attr'     => ['autocomplete' => 'off'],
            ])
            // Not a password anyone signs in with — it is a value pasted out
            // of a provider's console. `new-password` invited password managers
            // to offer generation, and in a wizard that renders one provider at
            // a time, to fill fields that were not on screen. There is no
            // standard way to say "this is not a login", hence the vendor hints.
            ->add('clientSecret', PasswordType::class, [
                'label'    => 'admin.integrations.field.client_secret',
                'required' => false,
                'mapped'   => false,
                'attr'     => [
                    'autocomplete'   => 'off',
                    'data-1p-ignore' => 'true',
                    'data-lpignore'  => 'true',
                    'data-bwignore'  => 'true',
                    'data-form-type' => 'other',
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
                        'autocomplete'   => 'off',
                        'data-1p-ignore' => 'true',
                        'data-lpignore'  => 'true',
                        'data-bwignore'  => 'true',
                        'data-form-type' => 'other',
                        'placeholder'  => $config instanceof MailProviderConfig && null !== $config->pushVerificationToken
                            ? 'admin.integrations.secret_unchanged'
                            : 'admin.integrations.secret_none',
                    ],
                ]);
        }

        // Setup only. The same registration covers Drive and Photos (Google) or
        // OneDrive (Microsoft), so ticking this saves doing the whole console
        // dance a second time — and takes those providers out of the wizard's
        // next step, since there is then nothing left to enter for them.
        if (true === $options['offer_inherit']) {
            $builder->add('inheritToIntegrations', CheckboxType::class, [
                'label'    => 'admin.integrations.inherit_toggle',
                'help'     => 'admin.integrations.inherit_toggle_help',
                'mapped'   => false,
                'required' => false,
                'data'     => true,
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

    /**
     * A client id with no secret behind it is not a registration — it is a
     * registration someone got halfway through. Saving it silently leaves an
     * install that looks configured and fails at the consent screen, so it is
     * refused here instead.
     *
     * Checked as a whole rather than per field because "is there a secret" has
     * two sources: the one just typed, and the one already stored.
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
        $stored    = $config instanceof MailProviderConfig && true === $config->hasClientSecret();
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
            ->setDefaults(['data_class' => MailProviderConfig::class, 'offer_inherit' => false])
            ->setRequired('mail_provider')
            ->setAllowedTypes('mail_provider', MailProvider::class)
            ->setAllowedTypes('offer_inherit', 'bool');
    }
}
