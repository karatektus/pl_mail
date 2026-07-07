<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class ComposeType extends AbstractType
{
    public function __construct(
        private readonly RouterInterface $router,
    )
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var UserInterface $user */
        $user = $options['user'];


        $builder
            // From — EntityType scoped to the current user's accounts
            ->add('mailbox', EntityType::class, [
                'label' => false,
                'class' => Mailbox::class,
                'choice_label' => fn(Mailbox $mailbox) => sprintf('%s <%s>',$mailbox->getAccount()->getName(),$mailbox->getAccount()->getEmail()),
                'query_builder' => function (MailboxRepository $repo) use ($user): QueryBuilder {
                    return  $repo->getActiveSentMailboxesForUser($user);
                },
                // Pre-select the primary account
//                'preferred_choices' => fn(Account $a) => $a->isPrimary(),
                'attr' => ['class' => 'compose-from-select'],
//                'data' => $options['selected_account'],
            ])

            // To — always visible, at least one entry required
            ->add('toAddresses', CollectionType::class, [
                'label' => false,
                'entry_type' => AddressEntryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__to__',
                'constraints' => [
                    new NotBlank(
                        message: 'At least one recipient is required.',
                        groups: ['send'],
                    ),
                ],
                'attr' => ['class' => 'compose-collection compose-to'],
            ])

            // Cc — hidden until the Cc button is clicked (Stimulus handles visibility)
            ->add('ccAddresses', CollectionType::class, [
                'label' => false,
                'entry_type' => AddressEntryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__cc__',
                'required' => false,
                'attr' => [
                    'class' => 'compose-collection compose-cc',
                    'data-compose-target' => 'ccField', // Stimulus target
                ],
            ])

            // Bcc — hidden until the Bcc button is clicked
            ->add('bccAddresses', CollectionType::class, [
                'label' => false,
                'entry_type' => AddressEntryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__bcc__',
                'required' => false,
                'attr' => [
                    'class' => 'compose-collection compose-bcc',
                    'data-compose-target' => 'bccField', // Stimulus target
                ],
            ])

            ->add('subject', TextType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['placeholder' => 'Subject'],
            ])

            ->add('bodyHtml', TextareaType::class, [
                'label' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => '',
                    'rows' => 10,
                    'data-compose-target' => 'body',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {

        $resolver->setDefaults([
            'data_class' => Message::class,
            'action' => $this->router->generate('app_compose_mail_send'),
            'method' => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'compose',
            'validation_groups' => ['Default'],
            'user' => null,
            'attr' => ['data-turbo-stream' => 'true'],
        ]);

        $resolver->setRequired('user');
    }
}
