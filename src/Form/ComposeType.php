<?php

namespace App\Form;

use App\Entity\Mailbox;
use App\Entity\Message;
use App\Repository\MailboxRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class ComposeType extends AbstractType
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var UserInterface $user */
        $user = $options['user'];

        $builder
            ->add('mailbox', EntityType::class, [
                'label' => false,
                'class' => Mailbox::class,
                'choice_label' => fn(Mailbox $mailbox) => sprintf(
                    '%s <%s>',
                    $mailbox->getAccount()->getName(),
                    $mailbox->getAccount()->getEmail(),
                ),
                'query_builder' => function (MailboxRepository $repo) use ($user): QueryBuilder {
                    return $repo->getActiveSentMailboxesForUser($user);
                },
                'attr' => ['class' => 'compose-from-select'],
            ])

            ->add('toAddresses', ContactAutocompleteField::class)

            ->add('ccAddresses', ContactAutocompleteField::class, [
                'required' => false,
            ])

            ->add('bccAddresses', ContactAutocompleteField::class, [
                'required' => false,
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
            ]);
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
