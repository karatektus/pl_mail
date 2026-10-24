<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The delete dialog's one question: where does the mail in this folder go.
 *
 * Only asked when there is mail to move — a label that is a tag, or a folder
 * that is empty, is deleted from the same dialog with no field at all. The choices
 * are LabelDeletion::destinations(), and the value that comes back is resolved
 * against the same list again by the controller, so the dropdown is a
 * convenience and not the rule — which is also why there is no constraint
 * here: the controller's refusal is the one that counts, and it says why.
 *
 * No CSRF field of its own. The delete route has always checked
 * `label-delete{id}` by hand, and both of the places that post to it share that
 * token; a second, form-level one would be a token for the same action under
 * another name.
 */
final class LabelDeleteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $destinations = $options['destinations'];

        // Nothing to ask: a tag, or folders with no mail in them. The form is
        // still built — the dialog is one template, and its footer and frame
        // come from the shared modal form — it just has no field.
        if ([] === $destinations) {
            return;
        }

        // Values as the choices and the names looked up beside them, rather
        // than array_flip(): flipping keys on the display name would quietly
        // drop one of two entries that read the same — a label of the user's
        // own called "Archive" beside the system Archive.
        $builder->add('destination', ChoiceType::class, [
            'label'                     => 'label.delete.destination',
            'choices'                   => array_keys($destinations),
            'choice_label'              => static fn (string $value): string => (string) $destinations[$value],
            'choice_translation_domain' => false,
            'placeholder'               => 'label.delete.destination_placeholder',
            'required'                  => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
        ]);

        // value => what the dropdown shows. Values are the keys of
        // LabelDeletion::destinations(): a role ("archive") or "label:{id}".
        $resolver->setRequired('destinations');
        $resolver->setAllowedTypes('destinations', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'label_delete';
    }
}
