<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Label\Label;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LabelType extends AbstractType
{
    /**
     * Tailwind color tokens rendered as chip backgrounds in the UI.
     * Stored as-is on Label::$color.
     */
    private const array COLORS = [
        'label.color.gray'   => 'gray',
        'label.color.red'    => 'red',
        'label.color.orange' => 'orange',
        'label.color.amber'  => 'amber',
        'label.color.green'  => 'green',
        'label.color.teal'   => 'teal',
        'label.color.blue'   => 'blue',
        'label.color.violet' => 'violet',
        'label.color.pink'   => 'pink',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user        = $options['user'];
        $editedLabel = $options['edited_label'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'label.form.name',
            ])
            // No account field: a label belongs to the user and spans every
            // account. Which accounts it is materialized on is decided by use,
            // and recorded on LabelBinding.
            ->add('parent', EntityType::class, [
                'label'         => 'label.form.parent',
                'class'         => Label::class,
                'choice_label'  => 'fullName',
                'required'      => false,
                'placeholder'   => 'label.form.no_parent',
                'query_builder' => function (EntityRepository $repository) use ($user) {
                    return $repository->createQueryBuilder('label')
                        ->where('label.usr = :usr')
                        ->andWhere('label.role IS NULL')
                        ->setParameter('usr', $user)
                        ->orderBy('label.name', 'ASC');
                },
                'choice_filter' => function (?Label $candidate) use ($editedLabel): bool {
                    if (null === $candidate || null === $editedLabel) {
                        return true;
                    }

                    // A label cannot be nested under itself or a descendant.
                    $cursor = $candidate;

                    while (null !== $cursor) {
                        if ($cursor === $editedLabel) {
                            return false;
                        }

                        $cursor = $cursor->parent;
                    }

                    return true;
                },
            ])
            ->add('color', ChoiceType::class, [
                'label'    => 'label.form.color',
                'choices'  => self::COLORS,
                'required' => false,
                'placeholder' => 'label.form.no_color',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => Label::class,
            'user'         => null,
            'edited_label' => null,
        ]);

        $resolver->setRequired('user');
    }
}
