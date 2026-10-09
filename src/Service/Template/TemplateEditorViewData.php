<?php

declare(strict_types=1);

namespace App\Service\Template;

use App\Domain\DTO\Template\TemplateToken;
use App\Domain\Enum\Template\DateFormatPreset;
use App\Domain\Enum\Template\TemplateVariable;
use App\Entity\Template\MailTemplate;
use App\Entity\User\User;
use App\Service\Mail\SignatureProvider;
use App\Service\Mail\UserAccounts;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the template editor needs beside the template itself: where it can be
 * filed, which variables exist, and the choices behind the two that have
 * settings.
 *
 * Its own class, like ProfileSectionViewData, because the editor is rendered
 * from three actions (new, edit, and a refused save) and the list is long
 * enough that three copies of it would not stay the same list.
 */
final readonly class TemplateEditorViewData
{
    public function __construct(
        private TemplateLibrary     $library,
        private TemplateRenderer    $renderer,
        private UserAccounts        $accounts,
        private SignatureProvider   $signatures,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, MailTemplate $template): array
    {
        return [
            'tree'             => $this->library->tree($user),
            'location'         => $this->library->locationOf($template)->key(),
            'variables'        => TemplateVariable::cases(),
            'chipLabels'       => $this->chipLabels($user),
            'dateFormats'      => $this->dateFormats($user),
            'signatureChoices' => $this->signatureChoices($user),
        ];
    }

    /**
     * The words a variable's chip is described with, for the script that draws
     * chips (assets/compose/template_variables.js#describe).
     *
     * Built here rather than in each template because chips are drawn in two
     * places — the editor, and the preview in the compose window's picker —
     * and a chip that read "Date +7 days" in one and "date" in the other would
     * look like two different variables.
     *
     * @return array{labels: array<string, string>, formats: array<string, string>, units: array<string, string>, signatures: array<string, string>}
     */
    public function chipLabels(User $user): array
    {
        $labels = [];

        foreach (TemplateVariable::cases() as $variable) {
            $labels[$variable->value] = $this->translator->trans($variable->labelKey());
        }

        $formats = [];

        foreach (DateFormatPreset::cases() as $preset) {
            $formats[$preset->value] = $this->translator->trans('settings.templates.format.' . $preset->value);
        }

        return [
            'labels'     => $labels,
            'formats'    => $formats,
            'units'      => [
                'd' => $this->translator->trans('settings.templates.unit.d'),
                'w' => $this->translator->trans('settings.templates.unit.w'),
                'm' => $this->translator->trans('settings.templates.unit.m'),
            ],
            'signatures' => array_column($this->signatureChoices($user), 'label', 'argument'),
        ];
    }

    /**
     * Each preset beside today written in it, because "long" means nothing
     * until it is a date.
     *
     * @return list<array{value: string, example: string}>
     */
    private function dateFormats(User $user): array
    {
        $formats = [];

        foreach (DateFormatPreset::cases() as $preset) {
            $formats[] = [
                'value'   => $preset->value,
                'example' => $this->renderer->dateExample(
                    new TemplateToken(TemplateVariable::Date, ['format' => $preset->value]),
                    $user,
                ),
            ];
        }

        return $formats;
    }

    /**
     * The signatures a template can name instead of following From: each
     * account's own, and each alias that signs differently from its account.
     *
     * Only the ones that exist. An account with no signature is not a choice,
     * and an alias that inherits would be a second name for its account's.
     * `argument` is what goes into the token (`account=12`), so the editor
     * never has to know how a signature is addressed.
     *
     * @return list<array{argument: string, label: string}>
     */
    private function signatureChoices(User $user): array
    {
        $choices = [];

        foreach ($this->accounts->all($user) as $account) {
            if (null !== $this->signatures->htmlFor($account, null)) {
                $choices[] = [
                    'argument' => sprintf('account=%d', $account->id),
                    'label'    => (string) ($account->displayAddress ?? $account->email),
                ];
            }

            foreach ($account->aliases as $alias) {
                $override = $account->getSetting($account::signatureAliasSetting((int) $alias->id));

                if (true === is_string($override) && '' !== trim($override)) {
                    $choices[] = [
                        'argument' => sprintf('alias=%d', $alias->id),
                        'label'    => $alias->address,
                    ];
                }
            }
        }

        return $choices;
    }
}
