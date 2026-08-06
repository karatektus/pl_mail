<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Domain\Exception\InvalidFirebaseCredentialsException;
use App\Entity\Push\FcmConfig;
use App\Form\PasswordManagerIgnore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The two Firebase files an installation needs, and the switch that puts them
 * into service.
 *
 * **Two files, because the two directions are separate.** The service-account
 * key is how this server sends; google-services.json is how the Android app
 * initialises Firebase, since plMail ships one APK from the Play Store and
 * cannot bake in a per-install project. Neither is any use alone, and the pair
 * must name the same project — a mismatch is invisible everywhere else, because
 * the app registers happily against one project and the server sends happily to
 * the other and the user simply never gets a notification.
 *
 * Both fields are unmapped and write-only, the rule MailProviderConfigType
 * applies to a client secret: the form never renders a stored value, so an
 * empty submission means "keep what is on file" rather than "throw it away". An
 * admin flipping the enable toggle must not have to re-paste a key they
 * downloaded once and did not keep.
 *
 * **The validation is the feature.** The Firebase console offers several
 * downloadable JSON files and they are all valid JSON; an install that saved
 * the wrong one would look configured, advertise `fcm: true` to every client,
 * and fail at the first grant with an error only the logs see. So both pastes
 * go through the same parsers the sender and the Session use, and the
 * exception's message is put on the field verbatim — naming the file it looks
 * like and the keys it is missing, because "invalid credentials" sends someone
 * back to a console with nothing to look for.
 *
 * TextareaType rather than PasswordType for the key, despite it being a
 * credential. It is a 2kB PEM-bearing blob that has to be pasted and, when it
 * will not validate, looked at; a masked field makes "did the paste include the
 * closing brace?" unanswerable. The stored value is never rendered back, which
 * is the property that matters.
 */
final class FcmConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $config = $options['data'];
        $hasKey = $config instanceof FcmConfig && true === $config->hasServiceAccount();
        $hasApp = $config instanceof FcmConfig && true === $config->hasClientConfig();

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->assertTheCredentialsAreUsable(...));

        $builder
            ->add('serviceAccountJson', TextareaType::class, [
                'label'    => 'admin.push.fcm.field.service_account',
                'required' => false,
                'mapped'   => false,
                'help'     => 'admin.push.fcm.field.service_account_help',
                'attr'     => $this->pasteAttributes($hasKey),
            ])
            ->add('googleServicesJson', TextareaType::class, [
                'label'    => 'admin.push.fcm.field.google_services',
                'required' => false,
                'mapped'   => false,
                'help'     => 'admin.push.fcm.field.google_services_help',
                'attr'     => $this->pasteAttributes($hasApp),
            ])
            ->add('isEnabled', CheckboxType::class, [
                'label'    => 'admin.push.fcm.field.enabled',
                'help'     => 'admin.push.fcm.field.enabled_help',
                'required' => false,
            ]);

        // Offered only when there is something to clear, so the option cannot
        // read as "tick this to remove the key I have not set". Only the secret
        // half can be cleared: the client config is public, replacing it is a
        // paste, and a "forget it" button would only ever be pressed by
        // accident.
        if (true === $hasKey) {
            $builder->add('clearServiceAccount', CheckboxType::class, [
                'label'    => 'admin.push.fcm.clear_key',
                'help'     => 'admin.push.fcm.clear_key_help',
                'required' => false,
                'mapped'   => false,
            ]);
        }
    }

    /**
     * Three refusals, and they are different questions.
     *
     * Each paste has to be the file it claims to be — checked whenever
     * something was typed, whatever the toggle says, because saving a broken
     * credential and finding out later is the failure this form exists to
     * prevent.
     *
     * The pair has to name one project. Checked by the entity rather than here,
     * so the rule lives with the data it constrains and the writer cannot skip
     * it; this only puts the message where the admin will see it.
     *
     * And the toggle has to have both halves behind it. Enabling early would
     * put `fcm: true` in every Session and refuse every create that believed
     * it, which is worse than the feature being off: a client cannot
     * distinguish that from a bug at its own end.
     */
    private function assertTheCredentialsAreUsable(FormEvent $event): void
    {
        $form   = $event->getForm();
        $config = $form->getData();

        $key = trim((string) $form->get('serviceAccountJson')->getData());
        $app = trim((string) $form->get('googleServicesJson')->getData());

        $hasKey  = $config instanceof FcmConfig && true === $config->hasServiceAccount();
        $hasApp  = $config instanceof FcmConfig && true === $config->hasClientConfig();
        $cleared = $form->has('clearServiceAccount') && true === $form->get('clearServiceAccount')->getData();

        if (false === $config instanceof FcmConfig) {
            return;
        }

        // Against a clone, so a refused submission leaves the entity Doctrine
        // is managing exactly as it was — this listener runs before the
        // controller decides whether to save, and a validated-in-place object
        // would be flushed by any later write in the same request.
        $candidate = clone $config;

        if (true === $cleared) {
            $candidate->forgetServiceAccount();
        }

        try {
            $candidate->useCredentials('' === $key ? null : $key, '' === $app ? null : $app);
        } catch (InvalidFirebaseCredentialsException $exception) {
            // The message verbatim rather than a translation key: it names the
            // specific keys this specific file is missing, or the two project
            // ids that disagree, which no fixed string can — and an admin
            // reading it is holding the files it describes.
            $field = str_contains($exception->getMessage(), 'google-services.json') && '' !== $app
                ? 'googleServicesJson'
                : 'serviceAccountJson';

            $form->get($field)->addError(new FormError($exception->getMessage()));

            return;
        }

        $willHaveKey = '' !== $key || (true === $hasKey && false === $cleared);
        $willHaveApp = '' !== $app || true === $hasApp;

        if (false === $form->get('isEnabled')->getData()) {
            return;
        }

        if (false === $willHaveKey) {
            $form->get('isEnabled')->addError(new FormError('admin.push.fcm.enable_without_key'));
        }

        if (false === $willHaveApp) {
            $form->get('isEnabled')->addError(new FormError('admin.push.fcm.enable_without_client'));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function pasteAttributes(bool $stored): array
    {
        return [
            'rows'         => 8,
            'spellcheck'   => 'false',
            'autocomplete' => 'off',
            'class'        => 'font-mono text-xs',
            'placeholder'  => true === $stored
                ? 'admin.push.fcm.paste_unchanged'
                : 'admin.push.fcm.paste_none',
        ] + PasswordManagerIgnore::ATTR;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FcmConfig::class]);
    }
}
