<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Connecting a CalDAV server, in the order the questions actually arise.
 *
 * The address first, alone, because it is the field a person can answer without
 * looking anything up and the one that decides whether the rest is worth
 * filling in — RFC 6764 bootstrapping will turn a bare domain into a calendar
 * home if the server advertises one, so "cloud.example.com" is a legitimate
 * answer and the help text says so.
 *
 * Then the credential, and this is where it differs from every other connect
 * form in the application. IntegrationConnectType offers one password field;
 * this offers two ways to fill it, and the second — reusing what plMail already
 * stores for a mail account — is a **deliberate tick that is never the
 * default**. The reason is in CalDavConnector's docblock and is repeated in the
 * field's help text, because it is the user's decision and they need it at the
 * moment they make it: the address above was typed by them and checked against
 * nothing, so sending a stored mail password to it is a thing to be asked for
 * rather than assumed. Most servers want an app-specific password anyway, and
 * iCloud and Fastmail refuse anything else.
 *
 * The two-ways-to-fill-one-field rule is the one thing a constraint cannot
 * express, so it is a POST_SUBMIT check: neither supplied is an error on the
 * password field, which is where a person is looking when they have not filled
 * it in.
 */
final class CalDavConnectType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $integration = $options['data'];
        $isNew       = false === $integration instanceof Integration || null === $integration->id;

        $builder
            ->add('baseUrl', UrlType::class, [
                'label'       => 'settings.calendars.caldav.field.address',
                'help'        => 'settings.calendars.caldav.field.address_help',
                'attr'        => ['placeholder' => 'https://cloud.example.com'] + PasswordManagerIgnore::ATTR,
                'constraints' => [new NotBlank(message: 'caldav.address_required')],
            ])
            ->add('name', TextType::class, [
                'label'       => 'settings.calendars.caldav.field.name',
                'help'        => 'settings.calendars.caldav.field.name_help',
                'attr'        => ['maxlength' => 100] + PasswordManagerIgnore::ATTR,
                'constraints' => [new Length(max: 100)],
            ])
            ->add('username', TextType::class, [
                'label'       => 'settings.calendars.caldav.field.username',
                'help'        => 'settings.calendars.caldav.field.username_help',
                'attr'        => ['autocomplete' => 'off'] + PasswordManagerIgnore::ATTR,
                'constraints' => [new NotBlank(message: 'caldav.username_required')],
            ])
            // Unmapped and unchecked by default. Rendering it checked, or
            // remembering the last answer, would both make reuse the thing that
            // happens when nobody reads the form.
            ->add('reuseMailPassword', CheckboxType::class, [
                'label'    => 'settings.calendars.caldav.field.reuse',
                'help'     => 'settings.calendars.caldav.field.reuse_help',
                'required' => false,
                'mapped'   => false,
                'data'     => false,
            ])
            ->add('reuseAccount', EntityType::class, [
                'label'        => 'settings.calendars.caldav.field.reuse_account',
                'class'        => Account::class,
                'choices'      => $options['lending_accounts'],
                'choice_label' => static fn (Account $account): string => (string) $account->displayAddress,
                'required'     => false,
                'mapped'       => false,
                'placeholder'  => 'settings.calendars.caldav.field.reuse_account_none',
            ])
            // Write-only, like every other credential field here: blank keeps
            // what is stored, so renaming a connection does not require
            // re-pasting an app password the user no longer has.
            ->add('secret', PasswordType::class, [
                'label'    => 'settings.calendars.caldav.field.secret',
                'help'     => 'settings.calendars.caldav.field.secret_help',
                'required' => false,
                'mapped'   => false,
                'attr'     => [
                    'autocomplete' => 'off',
                    'placeholder'  => true === $isNew ? '' : 'settings.integrations.field.secret_unchanged',
                ] + PasswordManagerIgnore::ATTR,
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->assertCredential(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class'       => Integration::class,
                'lending_accounts' => [],
            ])
            ->setAllowedTypes('lending_accounts', 'array');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * One of the two ways of supplying a credential has to have been used.
     *
     * Translated here rather than left as a key, because a message added to a
     * form by hand is rendered verbatim — only constraint violations arrive
     * pre-translated, which is why `admin.integrations.secret_missing` renders
     * as its own key today. The catalogue entry still lives in the `validators`
     * domain, with the constraint messages it belongs beside.
     */
    private function assertCredential(FormEvent $event): void
    {
        $form        = $event->getForm();
        $integration = $event->getData();
        $stored      = $integration instanceof Integration ? $integration->secret : null;

        $typed  = trim((string) $form->get('secret')->getData());
        $reused = true === $form->get('reuseMailPassword')->getData();

        if ('' !== $typed || null !== $stored) {
            return;
        }

        if (true === $reused && $form->get('reuseAccount')->getData() instanceof Account) {
            return;
        }

        $message = true === $reused ? 'caldav.reuse_account_required' : 'caldav.credential_required';

        $form->get(true === $reused ? 'reuseAccount' : 'secret')->addError(
            new FormError($this->translator->trans($message, [], 'validators')),
        );
    }
}
