<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Domain\Ai\ConnectionHeaders;
use App\Domain\Ai\EmbeddingPreset;
use App\Domain\Ai\KeepAlive;
use App\Domain\Enum\Ai\AiProvider;
use App\Entity\Ai\AiSettings;
use App\Form\PasswordManagerIgnore;
use App\Form\SecretType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

        // Read now, before a submission has been mapped onto the object: by the
        // time the listener below runs, $settings->baseUrl is already what was
        // typed, and the address the stored key was issued for is gone. Both
        // hosts, because each has a key and headers of its own.
        $addressBefore       = $settings instanceof AiSettings ? self::address($settings) : null;
        $searchAddressBefore = $settings instanceof AiSettings ? self::searchAddress($settings) : null;

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            static function (FormEvent $event) use ($addressBefore, $searchAddressBefore): void {
                $submitted = $event->getData();

                if (false === $submitted instanceof AiSettings) {
                    return;
                }

                $form = $event->getForm();

                // A field that is not in the form was neither typed in nor
                // ticked. The setup wizard leaves the advanced ones out, and
                // what is stored for them then simply follows its address.
                $typed  = static fn (string $field): string => true === $form->has($field) ? (string) $form->get($field)->getData() : '';
                $ticked = static fn (string $field): bool => true === $form->has($field) && true === $form->get($field)->getData();

                $address       = self::address($submitted);
                $searchAddress = self::searchAddress($submitted);

                $submitted->apiToken = self::keyAfter($submitted->apiToken, $typed('apiToken'), $addressBefore, $address);
                $submitted->headers  = self::headersAfter($submitted->headers, $typed('headers'), $ticked('clearHeaders'), $addressBefore, $address);

                $submitted->embeddingApiToken = self::keyAfter(
                    $submitted->embeddingApiToken,
                    $typed('embeddingApiToken'),
                    $searchAddressBefore,
                    $searchAddress,
                );

                $submitted->embeddingHeaders = self::headersAfter(
                    $submitted->embeddingHeaders,
                    $typed('embeddingHeaders'),
                    $ticked('clearEmbeddingHeaders'),
                    $searchAddressBefore,
                    $searchAddress,
                );
            },
        );

        $storedHeaders       = $settings instanceof AiSettings ? ConnectionHeaders::names($settings->headers) : [];
        $storedSearchHeaders = $settings instanceof AiSettings ? ConnectionHeaders::names($settings->embeddingHeaders) : [];
        $hasSearchToken      = $settings instanceof AiSettings && null !== $settings->embeddingApiToken;

        $builder
            ->add('isEnabled', CheckboxType::class, [
                'label'    => 'admin.ai.field.enabled',
                'help'     => 'admin.ai.field.enabled_help',
                'required' => false,
            ])
            // FIRST, because it decides what the fields under it mean: which
            // address is expected, and whether the keep-alive boxes and the
            // warm-up button are about anything at all.
            ->add('provider', EnumType::class, [
                'class'        => AiProvider::class,
                'label'        => 'admin.ai.field.provider',
                'help'         => 'admin.ai.field.provider_help',
                'choice_label' => static fn (AiProvider $provider): string => $provider->label(),
                'attr'         => [
                    'data-admin--ai-connection-target' => 'provider',
                    'data-action'                      => 'admin--ai-connection#refresh',
                ],
            ])
            ->add('baseUrl', TextType::class, self::addressField('admin.ai.field.base_url', 'admin.ai.field.base_url_help'))
            ->add('apiToken', SecretType::class, self::tokenField($hasToken));

        // Only on Admin → AI. The setup wizard is the short version of this
        // form: an address, a token, two models. Somebody who needs these knows
        // where the long version is.
        if (true === $options['advanced']) {
            $builder
                ->add('headers', TextareaType::class, self::headersField($storedHeaders))
                // The token is cleared by typing a new one or changing the
                // address. Headers need a gesture of their own, because "no
                // extra headers" is a perfectly good configuration to go back
                // to and an empty box already means "leave them alone".
                ->add('clearHeaders', CheckboxType::class, self::clearHeadersField())

                // ── A second host, for search ────────────────────────────
                // The switch and the four fields it brings with it: the same
                // four the first host has, built by the same helpers, so the
                // two cannot come to disagree about what an address or a
                // header is. See AiSettings::$embeddingOwnConnection for why
                // anybody wants this.
                ->add('embeddingOwnConnection', CheckboxType::class, [
                    'label'    => 'admin.ai.field.embedding_own',
                    'help'     => 'admin.ai.field.embedding_own_help',
                    'required' => false,
                    'attr'     => [
                        'data-admin--ai-connection-target' => 'ownConnection',
                        'data-action'                      => 'admin--ai-connection#refresh',
                    ],
                ])
                ->add('embeddingProvider', EnumType::class, [
                    'class'        => AiProvider::class,
                    'label'        => 'admin.ai.field.embedding_provider',
                    'choice_label' => static fn (AiProvider $provider): string => $provider->label(),
                    'attr'         => [
                        'data-admin--ai-connection-target' => 'searchProvider',
                        'data-action'                      => 'admin--ai-connection#refresh',
                    ],
                ])
                ->add('embeddingBaseUrl', TextType::class, self::addressField('admin.ai.field.embedding_base_url', 'admin.ai.field.base_url_help'))
                ->add('embeddingApiToken', SecretType::class, self::tokenField($hasSearchToken))
                ->add('embeddingHeaders', TextareaType::class, self::headersField($storedSearchHeaders))
                ->add('clearEmbeddingHeaders', CheckboxType::class, self::clearHeadersField());
        }

        $builder
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
            // STILL FREE TEXT, with a datalist over it. Anybody may run
            // anything, and a closed dropdown would go stale between releases
            // and refuse a model that works. The list is a shortcut, not a
            // gate — see the preset buttons in the template, which are where
            // the up- and downsides are actually written down.
            ->add('embeddingModel', TextType::class, [
                'label'    => 'admin.ai.field.embedding_model',
                'help'     => 'admin.ai.field.embedding_model_help',
                'required' => false,
                'attr'     => [
                    'placeholder'                        => EmbeddingPreset::Qwen3Embedding06b->value,
                    'autocomplete'                       => 'off',
                    'list'                               => 'embedding-model-presets',
                    'data-admin--embedding-preset-target' => 'model',
                ],
            ])
            // A TEXTAREA AND NOT AN INPUT, for one unglamorous reason: Qwen's
            // instruction contains a newline, and the model was trained on it.
            // A single-line input cannot hold one, so the field that exists to
            // carry the string would quietly drop the part that makes it work.
            ->add('searchQueryInstruction', TextareaType::class, [
                'label'    => 'admin.ai.field.search_query_instruction',
                'help'     => 'admin.ai.field.search_query_instruction_help',
                'required' => false,
                // TRIM OFF, AND IT IS LOAD-BEARING. Symfony trims text fields
                // by default, and every instruction that works ends in a
                // separator: Qwen's is "…\nQuery: " and nomic's is
                // "search_query: ". Trimmed, the query is glued onto the colon
                // — "Query:mails about food" — which tokenises differently from
                // what the model was trained on, and nothing anywhere reports
                // it. A caught test failure rather than a guess: this field
                // silently dropped that space the first time it was saved.
                'trim'     => false,
                'attr'     => [
                    'rows'                                => 2,
                    'autocomplete'                        => 'off',
                    'data-admin--embedding-preset-target' => 'instruction',
                ],
            ])
            ->add('semanticMinSimilarity', NumberType::class, [
                'label'       => 'admin.ai.field.semantic_min_similarity',
                'help'        => 'admin.ai.field.semantic_min_similarity_help',
                'required'    => true,
                'scale'       => 2,
                'html5'       => true,
                // Range and not Positive: the failure this guards is a number
                // outside the scale entirely. Above 1 nothing can ever match
                // and the feature goes silently dead; at or below 0 everything
                // matches and the results are the whole mailbox in an arbitrary
                // order. Both look like a broken search rather than a bad
                // setting, so neither is allowed to be saved.
                'constraints' => [new Range(min: 0.01, max: 1.0)],
                'attr'        => [
                    'step'                                => '0.01',
                    'min'                                 => '0.01',
                    'max'                                 => '1',
                    'data-admin--embedding-preset-target' => 'similarity',
                ],
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
            // The two that qualify the switch above rather than being features
            // of their own: whether arriving mail waits for the assistant
            // before it is shown in a tab, and for how long at most. See
            // AiSettings::$holdUntilClassified.
            ->add('holdUntilClassified', CheckboxType::class, [
                'label'    => 'admin.ai.field.hold',
                'help'     => 'admin.ai.field.hold_help',
                'required' => false,
            ])
            ->add('holdMaxSeconds', IntegerType::class, [
                'label'       => 'admin.ai.field.hold_max_seconds',
                'help'        => 'admin.ai.field.hold_max_seconds_help',
                'constraints' => [
                    new Range(min: AiSettings::MIN_HOLD_SECONDS, max: AiSettings::MAX_HOLD_SECONDS),
                ],
                'attr'        => [
                    'min' => AiSettings::MIN_HOLD_SECONDS,
                    'max' => AiSettings::MAX_HOLD_SECONDS,
                ],
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
     * A model host's address field. Both hosts have one.
     *
     * @return array<string, mixed>
     */
    private static function addressField(string $label, string $help): array
    {
        return [
            'label'       => $label,
            'help'        => $help,
            'required'    => false,
            'attr'        => ['placeholder' => 'http://10.0.0.5:11434', 'autocomplete' => 'off'],
            'constraints' => [
                // Loose on purpose. This names a box on the operator's own
                // network — a bare host, an IP, a .local name, a port that is
                // not 11434 — and a strict URL validator would reject half of
                // what is legitimately typed here. What it does refuse is a
                // scheme this cannot speak, which is the one mistake that
                // produces a silent nothing.
                new Regex(
                    pattern: '~^https?://[^\s]+$~i',
                    message: 'admin.ai.field.base_url_invalid',
                    match: true,
                ),
            ],
        ];
    }

    /**
     * A model host's token field.
     *
     * Unmapped and never re-rendered. A stored credential put back on screen is
     * a credential in a page cache, a screenshot and a browser's autofill — the
     * same rule FcmConfigType follows. What an empty box means is decided by
     * {@see keyAfter()}.
     *
     * @return array<string, mixed>
     */
    private static function tokenField(bool $hasToken): array
    {
        return [
            'label'      => 'admin.ai.field.token',
            'help'       => $hasToken ? 'admin.ai.field.token_help_set' : 'admin.ai.field.token_help',
            'required'   => false,
            'mapped'     => false,
            'empty_data' => '',
            'attr'       => ['placeholder' => $hasToken ? '••••••••' : ''],
        ];
    }

    /**
     * A model host's extra headers.
     *
     * A TEXTAREA OF `Name: value` LINES, unmapped like the token and for the
     * token's reason: what is stored is never put back on the page. The help
     * names the headers that are on file and nothing more. Typing anything
     * REPLACES the stored set — there is no way to edit one value without
     * seeing the others, and showing them is the thing this field does not do.
     *
     * @param list<string> $stored the names on file
     *
     * @return array<string, mixed>
     */
    private static function headersField(array $stored): array
    {
        return [
            'label'                       => 'admin.ai.field.headers',
            'help'                        => [] === $stored ? 'admin.ai.field.headers_help' : 'admin.ai.field.headers_help_set',
            'help_translation_parameters' => ['%names%' => implode(', ', $stored)],
            'required'                    => false,
            'mapped'                      => false,
            'empty_data'                  => '',
            'constraints'                 => [self::headersConstraint()],
            'attr'                        => [
                ...PasswordManagerIgnore::ATTR,
                'autocomplete' => 'off',
                'rows'         => 2,
                'spellcheck'   => 'false',
                'placeholder'  => 'X-Example-Key: value',
                'class'        => 'font-mono',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function clearHeadersField(): array
    {
        return [
            'label'    => 'admin.ai.field.headers_clear',
            'required' => false,
            'mapped'   => false,
        ];
    }

    /**
     * The key a connection holds once a submission has been read.
     *
     * HERE AND NOT IN A CONTROLLER, because this form has two: Admin → AI and
     * the setup wizard's AI step. The rule was written out in both, and a rule
     * about where a credential may go is not one to keep two copies of.
     *
     * THREE CASES
     * ───────────
     *  · Something was typed: that is the key now.
     *  · Nothing was typed and the address is the one the stored key was issued
     *    for: the stored key stays. The field is never filled from storage, so
     *    an empty box is what every save without a retyped credential looks
     *    like, and it must not be what clears one.
     *  · Nothing was typed and the address changed: the stored key is dropped.
     *    It was issued by whoever answers at the old address, and from now on
     *    every request goes to the new one. Carrying it over would hand a
     *    credential to a host that was never given it — by an administrator who
     *    only meant to change a URL.
     *
     */
    private static function keyAfter(?string $stored, string $typed, ?string $addressBefore, string $addressAfter): ?string
    {
        if ('' !== trim($typed)) {
            return $typed;
        }

        if ($addressBefore !== $addressAfter) {
            return null;
        }

        return $stored;
    }

    /**
     * The extra headers a connection holds once a submission has been read.
     *
     * keyAfter()'s three cases, and a fourth. A value here is a credential for
     * whatever stands at the address, so it follows the address exactly as the
     * key does: kept while the address stays, dropped when it changes.
     *
     * The fourth case is the tick box. Dropped on request, for the installation
     * that has taken its proxy away and now sends a header to nobody.
     *
     * What was typed wins over the tick box. Somebody who typed a new set and
     * left the box ticked from a moment ago means the new set.
     *
     * Text that does not parse leaves the stored set alone. The constraint on
     * the field has already made the form invalid, so nothing is saved either
     * way; this only keeps the object from being left holding half an edit.
     */
    private static function headersAfter(?string $stored, string $typed, bool $clear, ?string $addressBefore, string $addressAfter): ?string
    {
        if ('' !== trim($typed)) {
            try {
                return ConnectionHeaders::encode(ConnectionHeaders::parse($typed));
            } catch (\InvalidArgumentException) {
                return $stored;
            }
        }

        if (true === $clear || $addressBefore !== $addressAfter) {
            return null;
        }

        return $stored;
    }

    /**
     * Refuses header text that cannot be sent as written.
     *
     * {@see ConnectionHeaders::parse()} decides and names the reason; this only
     * carries it to the field. The reason is a key in the `validators` domain
     * and never quotes what was typed — what was typed is a credential.
     */
    private static function headersConstraint(): Callback
    {
        return new Callback(static function (?string $typed, ExecutionContextInterface $context): void {
            if (null === $typed || '' === trim($typed)) {
                return;
            }

            try {
                ConnectionHeaders::parse($typed);
            } catch (\InvalidArgumentException $exception) {
                $context->buildViolation($exception->getMessage())
                    ->setParameter('%max%', (string) ConnectionHeaders::MAX_HEADERS)
                    ->addViolation();
            }
        });
    }

    /**
     * What a key was issued for: the dialect and the address, as one string.
     *
     * The dialect counts. The same host and port can be an Ollama at its root
     * and something else entirely behind a proxy path, and "the provider
     * changed" is at least as strong a sign as "the URL changed" that whoever
     * answers now is not who the key was typed for.
     *
     * A trailing slash does not count. People paste one and people remove one,
     * and both clients tolerate it.
     */
    private static function address(AiSettings $settings): string
    {
        return $settings->provider->value . ' ' . rtrim(trim((string) $settings->baseUrl), '/');
    }

    /**
     * The same, for the search host.
     *
     * Read off the four embedding columns whether or not the switch is on. A
     * search host that is switched off and on again is still the same host, and
     * its key is still its key; it is the address changing that makes a stored
     * credential somebody else's.
     */
    private static function searchAddress(AiSettings $settings): string
    {
        return $settings->embeddingProvider->value . ' ' . rtrim(trim((string) $settings->embeddingBaseUrl), '/');
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
            // The fields most installations never touch. On by default, so the
            // admin page is the whole form; the setup wizard turns it off.
            'advanced'           => true,
        ]);

        $resolver->setAllowedTypes('advanced', 'bool');
    }
}
