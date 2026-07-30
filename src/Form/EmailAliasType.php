<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Adding a send-as address to an account.
 *
 * Unmapped: EmailAlias is constructed with its account, source and status, and
 * the address is normalised on the way in, so the form carries the raw string
 * and the controller builds the entity. The duplicate check stays in the
 * controller — it needs the account, which the form does not have.
 */
final class EmailAliasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('address', EmailType::class, [
            // Inline row next to the Add button — see ApiTokenType.
            'label' => false,
            'attr'  => ['placeholder' => 'settings.aliases.add_placeholder'],
            'constraints' => [
                new NotBlank(message: 'alias.invalid'),
                new Email(message: 'alias.invalid'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'alias_add',
        ]);
    }
}
