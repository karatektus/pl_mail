<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Represents a single recipient entry: ['name' => '...', 'address' => '...']
 *
 * Used as the entry_type in CollectionType for toAddresses, ccAddresses, bccAddresses.
 */
class AddressEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('address', EmailType::class, [
                'label' => false,
                'row_attr' => ['class' => 'contents'],
                'attr' => [
                    'placeholder' => 'Email address',
                    'class' => 'flex-1 text-sm text-gray-800 outline-none bg-transparent placeholder-gray-400 min-w-0',
                    'autocomplete' => 'email',
                ],
                'constraints' => [
                    new NotBlank(groups: ['send']),
                    new Email(groups: ['send']),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => false,
                'required' => false,
                'row_attr' => ['class' => 'contents'],
                'attr' => [
                    'placeholder' => 'Name',
                    'class' => 'w-28 text-sm text-gray-500 outline-none bg-transparent placeholder-gray-300 border-l border-gray-200 pl-2 min-w-0',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Each entry maps to a plain array, not an entity
            'data_class' => null,
        ]);
    }
}
