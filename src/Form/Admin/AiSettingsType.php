<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Ai\AiSettings;
use App\Form\PasswordManagerIgnore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The model host, and what it is allowed to do.
 *
 * The two model names are plain text rather than a dropdown of what the host
 * holds, and that is deliberate: the dropdown would have to be populated by
 * asking a machine that may not be switched on, which turns "open the settings
 * page" into "wait five seconds and then read an error". The page offers a
 * **Test** button instead, which asks once, on purpose, and reports what it
 * found — including whether the model that has been typed is actually there.
 */
final class AiSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $settings = $options['data'];
        $hasToken = $settings instanceof AiSettings && null !== $settings->apiToken;

        $builder
            ->add('isEnabled', CheckboxType::class, [
                'label'    => 'admin.ai.field.enabled',
                'help'     => 'admin.ai.field.enabled_help',
                'required' => false,
            ])
            ->add('baseUrl', TextType::class, [
                'label'       => 'admin.ai.field.base_url',
                'help'        => 'admin.ai.field.base_url_help',
                'required'    => false,
                'attr'        => ['placeholder' => 'http://10.0.0.5:11434', 'autocomplete' => 'off'],
                'constraints' => [
                    // Loose on purpose. This names a box on the operator's own
                    // network — a bare host, an IP, a .local name, a port that
                    // is not 11434 — and a strict URL validator would reject
                    // half of what is legitimately typed here. What it does
                    // refuse is a scheme this cannot speak, which is the one
                    // mistake that produces a silent nothing.
                    new Regex(
                        pattern: '~^https?://[^\s]+$~i',
                        message: 'admin.ai.field.base_url_invalid',
                        match: true,
                    ),
                ],
            ])
            ->add('apiToken', PasswordType::class, [
                'label'       => 'admin.ai.field.token',
                'help'        => $hasToken ? 'admin.ai.field.token_help_set' : 'admin.ai.field.token_help',
                'required'    => false,
                'mapped'      => false,
                'empty_data'  => '',
                // Never re-rendered. A stored credential put back on screen is
                // a credential in a page cache, a screenshot and a browser's
                // autofill — the same rule FcmConfigType follows.
                // Spread, like every other credential field here: without it a
                // password manager's overlay survives the Turbo frame swap and
                // sits over the field it was anchored to.
                'attr'        => [...PasswordManagerIgnore::SECRET, 'placeholder' => $hasToken ? '••••••••' : ''],
            ])
            ->add('chatModel', TextType::class, [
                'label'    => 'admin.ai.field.chat_model',
                'help'     => 'admin.ai.field.chat_model_help',
                'required' => false,
                'attr'     => ['placeholder' => 'llama3.1:8b', 'autocomplete' => 'off'],
            ])
            ->add('embeddingModel', TextType::class, [
                'label'    => 'admin.ai.field.embedding_model',
                'help'     => 'admin.ai.field.embedding_model_help',
                'required' => false,
                'attr'     => ['placeholder' => 'nomic-embed-text', 'autocomplete' => 'off'],
            ])
            // The three below are separate because they have very different
            // costs and very different appetites for being wrong. See
            // AiSettings for the argument.
            ->add('searchEnabled', CheckboxType::class, [
                'label'    => 'admin.ai.field.search',
                'help'     => 'admin.ai.field.search_help',
                'required' => false,
            ])
            ->add('categorisationEnabled', CheckboxType::class, [
                'label'    => 'admin.ai.field.categorisation',
                'help'     => 'admin.ai.field.categorisation_help',
                'required' => false,
            ])
            ->add('writingHelpEnabled', CheckboxType::class, [
                'label'    => 'admin.ai.field.writing_help',
                'help'     => 'admin.ai.field.writing_help_help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => AiSettings::class,
            'translation_domain' => 'messages',
        ]);
    }
}
