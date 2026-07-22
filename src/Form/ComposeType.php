<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Message;
use App\Repository\AccountRepository;
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
            // Unmapped: Message has no account column — the controller reads
            // this field and wires the Drafts label + IMAP drafts mailbox via
            // applyAccount(). Pre-set on render with $form->get('account')->setData().
            ->add('account', EntityType::class, [
                'label' => false,
                'mapped' => false,
                'class' => Account::class,
                'choice_label' => fn(Account $account) => sprintf(
                    '%s <%s>',
                    $account->getName(),
                    $account->getEmail(),
                ),
                'query_builder' => function (AccountRepository $repo) use ($user): QueryBuilder {
                    return $repo->createQueryBuilder('account')
                        ->where('account.usr = :usr')
                        ->andWhere('account.isActive = :isActive')
                        ->setParameter('usr', $user)
                        ->setParameter('isActive', true)
                        ->orderBy('account.sortOrder', 'ASC')
                        ->addOrderBy('account.email', 'ASC');
                },
                'attr' => ['class' => 'compose-from-select'],
            ])

            ->add('toAddresses', ContactAutocompleteField::class, [
                'mapped' => false,
            ])

            ->add('ccAddresses', ContactAutocompleteField::class, [
                'required' => false,
                'mapped' => false,
            ])

            ->add('bccAddresses', ContactAutocompleteField::class, [
                'required' => false,
                'mapped' => false,
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
