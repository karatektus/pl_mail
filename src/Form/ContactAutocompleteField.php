<?php

namespace App\Form;

use App\Entity\Contact;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class ContactAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class'                => Contact::class,
            'placeholder'          => '',
            'multiple'             => true,
            'autocomplete'         => true,
            'allow_options_create' => true,
            'searchable_fields'    => ['email', 'displayName'],
            'choice_label'         => fn(Contact $c) => $c->getDisplayName()
                ? sprintf('%s <%s>', $c->getDisplayName(), $c->getEmail())
                : $c->getEmail(),

            'tom_select_options' => [
                'plugins'          => ['remove_button'],
                'persist'          => false,
                'closeAfterSelect' => false,
                'openOnFocus'      => false,
                'hideSelected'     => true,

                'render' => [
                    // Dropdown suggestion row: avatar initial + name + email
                    'option' => "function(data, escape) {
                        var raw   = data.text ? data.text.replace(/<[^>]+>/g, '') : (data.value || '');
                        var match = raw.match(/^(.+?) <([^>]+)>$/);
                        var displayName  = match ? escape(match[1]) : '';
                        var displayEmail = match ? escape(match[2]) : escape(raw);
                        var initial = displayName
                            ? displayName.replace(/<[^>]*>/g, '').trim().split(/\\s+/).map(function(w){ return w[0]; }).slice(0,2).join('').toUpperCase()
                            : (displayEmail[0] || '?').toUpperCase();
                        return '<div class=\"ts-option-row\">'
                            + '<span class=\"ts-option-avatar\">' + initial + '</span>'
                            + '<span class=\"ts-option-text\">'
                            + (displayName ? '<span class=\"ts-option-name\">' + displayName + '</span>' : '')
                            + '<span class=\"ts-option-email\">' + displayEmail + '</span>'
                            + '</span></div>';
                    }",

                    // Selected chip: name (or email) + × remove button
                    'item' => "function(data, escape) {
            var raw   = data.text ? data.text.replace(/<[^>]+>/g, '') : (data.value || '');
            var match = raw.match(/^(.+?) <[^>]+>$/);
            var label = match ? escape(match[1]) : escape(raw);
            return '<div>' + label + '</div>';
        }",

                    // "Add <typed>" create row
                    'option_create' => "function(data, escape) {
                        return '<div class=\"ts-option-create\">'
                            + '<span class=\"ts-option-create-icon\">+</span>'
                            + 'Add <strong>' + escape(data.input) + '</strong>'
                            + '</div>';
                    }",

                    // No results
                    'no_results' => "function(data, escape) {
                        return '<div class=\"ts-no-results\">No contacts found</div>';
                    }",
                ],
            ],
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
