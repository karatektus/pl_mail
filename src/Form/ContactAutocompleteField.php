<?php

namespace App\Form;

use App\Entity\Contact;
use App\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class ContactAutocompleteField extends AbstractType
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly Security          $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // allow_options_create only enables the "Add …" row client-side. The
        // bundle submits entity IDs, so a created option arrives as the raw
        // typed address and the choice loader rejects it. Materialise a
        // Contact first and hand the loader the id it expects.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->resolveCreatedOptions(...));
    }

    private function resolveCreatedOptions(PreSubmitEvent $event): void
    {
        $submitted = $event->getData();

        if (false === is_array($submitted)) {
            return;
        }

        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return;
        }

        $typed = [];

        foreach ($submitted as $value) {
            if (true === is_numeric($value)) {
                continue;
            }

            $email = mb_strtolower(trim((string) $value));

            if (false === (bool) filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $typed[$email] = $email;
        }

        if (count($typed) === 0) {
            return;
        }

        $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($typed));
        $missing  = array_values(array_diff(array_keys($typed), array_keys($contacts)));

        if (count($missing) > 0) {
            $this->contactRepository->createUnsent($user, $missing);
            $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($typed));
        }

        $resolved = [];

        foreach ($submitted as $value) {
            if (true === is_numeric($value)) {
                $resolved[] = $value;

                continue;
            }

            $email = mb_strtolower(trim((string) $value));

            // Anything that still doesn't resolve was not a valid address —
            // dropping it here beats failing the whole form on one typo.
            if (true === array_key_exists($email, $contacts)) {
                $resolved[] = (string) $contacts[$email]->getId();
            }
        }

        $event->setData($resolved);
    }

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
