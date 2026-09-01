<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Domain\Ai\KeepAlive;
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
            // Directly under the model it governs rather than grouped with the
            // other keep-alive below, because the pair is one decision: which
            // model, and for how long it stays loaded. Two adjacent duration
            // fields under two adjacent model fields would be four boxes in
            // which it is genuinely hard to see which belongs to which.
            ->add('chatKeepAlive', TextType::class, self::keepAlive(
                'admin.ai.field.chat_keep_alive',
                'admin.ai.field.chat_keep_alive_help',
                KeepAlive::DEFAULT_CHAT,
            ))
            ->add('embeddingModel', TextType::class, [
                'label'    => 'admin.ai.field.embedding_model',
                'help'     => 'admin.ai.field.embedding_model_help',
                'required' => false,
                'attr'     => ['placeholder' => 'nomic-embed-text', 'autocomplete' => 'off'],
            ])
            ->add('embeddingKeepAlive', TextType::class, self::keepAlive(
                'admin.ai.field.embedding_keep_alive',
                'admin.ai.field.embedding_keep_alive_help',
                KeepAlive::DEFAULT_EMBEDDING,
            ))
            // The four below are separate because they have very different
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
            ])
            ->add('summaryEnabled', CheckboxType::class, [
                'label'    => 'admin.ai.field.summary',
                'help'     => 'admin.ai.field.summary_help',
                'required' => false,
            ]);
    }

    /**
     * The two keep-alive fields, which differ only in what they say.
     *
     * WHY THE VALIDATION IS A REGEX AND NOT A COERCION
     * ────────────────────────────────────────────────
     * A wrong value here fails at the far end, hours later, on somebody else's
     * request: Ollama answers 400 for a duration it cannot parse, and the only
     * symptom is that the writing help stopped working. Quietly rewriting "5
     * min" into "5m" would hide that, and quietly falling back to the default
     * would hide it better — the field would show one thing and the host would
     * be told another, which is the single worst outcome available for a
     * setting whose entire job is to be believed.
     *
     * So it is refused at the moment somebody can still fix it, with the
     * accepted spellings in the message. {@see KeepAlive::PATTERN} is the same
     * expression the request body is built from, so the form and the wire
     * cannot come to disagree about what is legal.
     *
     * The message lives in the `validators` domain, which is not the domain
     * this form declares — Symfony translates constraint output there
     * regardless, and a key put in `messages` renders on screen as the raw key.
     * See CODESTYLE §8.2, and the `base_url_invalid` message directly above,
     * which has that bug.
     *
     * @return array<string, mixed>
     */
    private static function keepAlive(string $label, string $help, string $shipped): array
    {
        return [
            'label'       => $label,
            'help'        => $help,
            // Empty is a real, selectable answer — "say nothing and let the
            // host's own OLLAMA_KEEP_ALIVE stand" — so the field is optional
            // and the placeholder shows what plMail ships rather than what is
            // required.
            'required'    => false,
            'attr'        => ['placeholder' => $shipped, 'autocomplete' => 'off'],
            'constraints' => [
                new Regex(
                    pattern: KeepAlive::PATTERN,
                    message: 'admin.ai.keep_alive_invalid',
                    match: true,
                ),
            ],
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => AiSettings::class,
            'translation_domain' => 'messages',
        ]);
    }
}
