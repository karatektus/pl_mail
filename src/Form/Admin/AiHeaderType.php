<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\PasswordManagerIgnore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;

final class AiHeaderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'admin.ai.headers.name', 'required' => false, 'trim' => false])
            ->add('mode', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, ['label' => 'admin.ai.headers.mode', 'choices' => ['admin.ai.headers.fixed' => 'fixed', 'admin.ai.headers.session' => 'session'], 'empty_data' => 'fixed'])
            ->add('value', PasswordType::class, ['label' => 'admin.ai.headers.value_short', 'required' => false, 'trim' => false,
                'always_empty' => true, 'empty_data' => '', 'attr' => [...PasswordManagerIgnore::SECRET]]);
    }
    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefault('saved_names', []);
        $resolver->setAllowedTypes('saved_names', 'array');
    }
    public function buildView(\Symfony\Component\Form\FormView $view, \Symfony\Component\Form\FormInterface $form, array $options): void
    {
        $view->vars['has_saved_value'] = in_array($form->get('name')->getData(), $options['saved_names'], true);
    }
}
