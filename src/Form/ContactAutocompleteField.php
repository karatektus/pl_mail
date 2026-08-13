<?php

namespace App\Form;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Contact;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use Doctrine\ORM\QueryBuilder;
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
        //
        // The priority is load-bearing: ChoiceType registers its own PRE_SUBMIT
        // at 0, which runs every submitted value through the choice list and
        // drops the ones it does not know — a typed address among them. At the
        // default priority this listener only ever saw what survived that
        // filter (an empty array), and the form failed with "The selected
        // choice is invalid". Running first, it hands the filter ids instead.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->resolveCreatedOptions(...), 512);
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

            $email = AddressHelper::email((string) $value);

            // Normalised first: a pasted `"Name" <a@b>` is a perfectly good
            // address once the wrapper is off, and used to be dropped here.
            if (false === AddressHelper::isValidEmail($email)) {
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

            $email = AddressHelper::email((string) $value);

            // Anything that still doesn't resolve was not a valid address —
            // dropping it here beats failing the whole form on one typo.
            if (true === array_key_exists($email, $contacts)) {
                $resolved[] = (string) $contacts[$email]->id;
            }
        }

        $event->setData($resolved);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class'                => Contact::class,

            // Scope every contact this field can see or accept to the signed-in
            // user. Without it `class` alone means "any Contact row in the
            // database", and Contact is per-user (uniq_contact_user_email, and
            // a usr_id FK) — so this was a cross-tenant hole with two mouths:
            //
            //  • the suggestion endpoint. WrappedEntityTypeAutocompleter takes
            //    this very option as the base query and falls back to an
            //    unfiltered createQueryBuilder(), so typing three letters
            //    listed other people's correspondents by name AND address.
            //  • the submitted value. The field posts contact IDS, and
            //    resolveCreatedOptions() below waves numerics straight through
            //    to the choice list — which, unscoped, happily resolved another
            //    user's id and addressed the message to their contact.
            //
            // One builder closes both, because the bundle reads it for the
            // endpoint and EntityType folds it into the choice loader that
            // validates the submission. LabelType does the same thing; this
            // field was the outlier.
            'query_builder'        => function (ContactRepository $repository): QueryBuilder {
                $builder = $repository->createQueryBuilder('entity');
                $user    = $this->security->getUser();

                // The form is built in contexts with no session too, and
                // "no user" must mean "no contacts" rather than "all of them".
                if (false === $user instanceof User) {
                    return $builder->andWhere('1 = 0');
                }

                return $builder
                    ->andWhere('entity.usr = :usr')
                    ->setParameter('usr', $user);
            },

            'placeholder'          => '',
            'multiple'             => true,
            'autocomplete'         => true,
            'allow_options_create' => true,
            'searchable_fields'    => ['email', 'displayName'],
            'choice_label'         => fn(Contact $c) => $c->displayName
                ? sprintf('%s <%s>', $c->displayName, $c->email)
                : $c->email,

            'tom_select_options' => [
                'plugins'          => ['remove_button'],
                'persist'          => false,
                'closeAfterSelect' => false,
                'openOnFocus'      => false,
                'hideSelected'     => true,

                // Tab commits the highlighted suggestion (or the typed
                // address) instead of leaving the field with it half-entered.
                // With the dropdown closed Tom Select leaves the keystroke
                // alone, and compose_controller moves focus on from there.
                'selectOnTab'      => true,

                // allow_options_create above only reaches Tom Select in newer
                // releases of the bundle: this one renders it as a valueless
                // data attribute, which Stimulus reads back as false, so the
                // "Add …" row never appeared and an address that is not
                // already a Contact could not be entered at all. Passing it
                // through here is the same switch, one layer down.
                'create'           => true,
                'createFilter'     => '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$',

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
