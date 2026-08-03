<?php

namespace App\Form;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Repository\Mail\AccountRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class ComposeType extends AbstractType
{
    public function __construct(
        private readonly RouterInterface   $router,
        private readonly AccountRepository $accountRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var UserInterface $user */
        $user = $options['user'];

        $builder
            // Unmapped. Value is a "accountId|address" token: the option the
            // user picks resolves BOTH the sending account (Drafts label +
            // IMAP drafts mailbox, via applyAccount()) and the exact From
            // address. One option per sendable alias; accounts with no aliases
            // yet contribute a single option for their display address.
            // Pre-set on render with ->setData($this->senderToken($account)).
            ->add('account', ChoiceType::class, [
                'label'        => false,
                'mapped'       => false,
                'choices'      => $this->fromChoices($user),
                'choice_value' => static fn (?string $token): string => $token ?? '',
                'attr'         => ['class' => 'compose-from-select'],
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
                    'data-compose--compose-target' => 'body',
                ],
            ]);
    }

    /**
     * @return array<string,string>  label => "accountId|address" token
     */
    private function fromChoices(UserInterface $user): array
    {
        $choices = [];

        foreach ($this->accountRepository->findForUserOrdered($user) as $account) {
            if (false === (bool) $account->isActive) {
                continue;
            }

            $sendable = $account->sendableAliases;

            if (count($sendable) === 0) {
                $address = $account->displayAddress ?? $account->email;

                if (null !== $address && '' !== $address) {
                    $choices[$this->fromLabel($address, $account->name)] = $account->id . '|' . $address;
                }

                continue;
            }

            foreach ($sendable as $alias) {
                $choices[$this->fromLabel($alias->address, $alias->displayName ?? $account->name)]
                    = $account->id . '|' . $alias->address;
            }
        }

        return $choices;
    }

    private function fromLabel(string $address, ?string $name): string
    {
        $name = null !== $name ? trim($name) : '';

        return '' !== $name ? sprintf('%s <%s>', $name, $address) : $address;
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
