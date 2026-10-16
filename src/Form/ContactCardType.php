<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Helper\AddressHelper;
use App\Entity\AddressBook\AddressBook;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One contact's editable fields — the ones VCardFields carries.
 *
 * Not mapped to ContactCard. The card's columns are a projection of its vCard,
 * and writing to them directly would let the two disagree; the controller
 * hands the submitted values to ContactCardEditor, which edits the vCard and
 * projects it again.
 *
 * Addresses and numbers are one per line rather than a collection of rows:
 * nobody has eleven, and a textarea is the one control that works the same
 * with a keyboard, a phone and a screen reader without any JavaScript.
 *
 * The address book is asked for only when creating. Moving a card between
 * books is a delete on one server collection and a create on another, which is
 * a different operation from editing it.
 */
final class ContactCardType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ([] !== $options['books']) {
            $builder->add('addressBook', EntityType::class, [
                'label'        => 'contacts.field.address_book',
                'class'        => AddressBook::class,
                'choices'      => $options['books'],
                'choice_label' => static fn (AddressBook $book): string => $book->name,
            ]);
        }

        $builder
            ->add('fullName', TextType::class, [
                'label'       => 'contacts.field.name',
                'required'    => false,
                'attr'        => ['maxlength' => 255, 'autocomplete' => 'off'],
                'constraints' => [new Length(max: 255)],
            ])
            ->add('emails', TextareaType::class, [
                'label'    => 'contacts.field.emails',
                'help'     => 'contacts.field.one_per_line',
                'required' => false,
                'attr'     => ['rows' => 2, 'autocomplete' => 'off'],
            ])
            ->add('phones', TextareaType::class, [
                'label'    => 'contacts.field.phones',
                'help'     => 'contacts.field.one_per_line',
                'required' => false,
                'attr'     => ['rows' => 2, 'autocomplete' => 'off'],
            ])
            ->add('organization', TextType::class, [
                'label'       => 'contacts.field.organization',
                'required'    => false,
                'attr'        => ['maxlength' => 255],
                'constraints' => [new Length(max: 255)],
            ])
            ->add('note', TextareaType::class, [
                'label'    => 'contacts.field.note',
                'required' => false,
                'attr'     => ['rows' => 3],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->assertContents(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => null,
                'books'      => [],
            ])
            ->setAllowedTypes('books', 'array');
    }

    /**
     * Split a one-per-line field into its non-empty lines.
     *
     * @return list<string>
     */
    public static function lines(mixed $value): array
    {
        $lines = preg_split('/\R/', is_string($value) ? $value : '') ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => '' !== $line,
        )));
    }

    /**
     * A contact needs something to call it by, and every address must be one.
     *
     * Translated here for the reason CalDavConnectType gives: an error added
     * by hand is rendered verbatim.
     */
    private function assertContents(FormEvent $event): void
    {
        $form   = $event->getForm();
        $emails = self::lines($form->get('emails')->getData());

        foreach ($emails as $email) {
            if (false === AddressHelper::isValidEmail($email)) {
                $form->get('emails')->addError(new FormError(
                    $this->translator->trans('contacts.invalid_email', ['%email%' => $email], 'validators'),
                ));

                return;
            }
        }

        $name = trim((string) $form->get('fullName')->getData());

        if ('' === $name && [] === $emails) {
            $form->get('fullName')->addError(new FormError(
                $this->translator->trans('contacts.name_or_email_required', [], 'validators'),
            ));
        }
    }
}
