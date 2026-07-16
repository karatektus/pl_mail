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
            'class'             => Contact::class,
            'placeholder'       => '',
            'multiple'          => true,
            'autocomplete'      => true,
            'searchable_fields' => ['email', 'displayName'],
            'choice_label'      => fn(Contact $c) => $c->getDisplayName()
                ? sprintf('%s <%s>', $c->getDisplayName(), $c->getEmail())
                : $c->getEmail(),

            // Tom Select option + item renderers injected as JS strings.
            // UX Autocomplete passes these through to Tom Select's `render` config.
            'tom_select_options' => [
                'render' => [
                    // Suggestion row in the dropdown
                    'option' => "function(data, escape) {
                        var name  = data.text ? data.text.replace(/<[^>]+>/g, '') : '';
                        var email = data.value || '';
                        // If choice_label formatted it as 'Name <email>', extract parts
                        var match = name.match(/^(.+?) <([^>]+)>$/);
                        var displayName = match ? escape(match[1]) : '';
                        var displayEmail = match ? escape(match[2]) : escape(email);
                        var initials = displayName
                            ? displayName.replace(/<[^>]*>/g, '').trim().split(/\\s+/).map(function(w){ return w[0]; }).slice(0,2).join('').toUpperCase()
                            : displayEmail[0].toUpperCase();
                        return '<div class=\"flex items-center gap-2.5 py-0.5\">'
                            + '<span class=\"ts-option-avatar\">' + initials + '</span>'
                            + '<span class=\"ts-option-text\">'
                            + (displayName ? '<span class=\"ts-option-name\">' + displayName + '</span>' : '')
                            + '<span class=\"ts-option-email\">' + displayEmail + '</span>'
                            + '</span></div>';
                    }",
                    // Selected chip label
                    'item' => "function(data, escape) {
                        var name = data.text ? data.text.replace(/<[^>]+>/g, '') : data.value;
                        var match = name.match(/^(.+?) <[^>]+>$/);
                        var label = match ? escape(match[1]) : escape(name);
                        return '<div>' + label + '</div>';
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
