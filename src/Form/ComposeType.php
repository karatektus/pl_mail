<?php

namespace App\Form;

use App\Domain\Enum\Mail\MessagePriority;
use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Repository\Mail\AccountRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

        $draft   = ($options['data'] ?? null) instanceof Message ? $options['data'] : null;
        $choices = $this->fromChoices($user);

        // A draft already going out from a catch-all address — a reply to mail
        // that came in for one — needs that address among the options, or the
        // window could not show what it is about to send as.
        //
        // isset(), because a message that has not been filed under an account
        // yet — the one send() binds a first POST to — has no account to read:
        // the property is typed and uninitialised, not null.
        if (null !== $draft && true === isset($draft->account, $draft->fromAddress)) {
            $choices += $this->catchAllChoice($user, $draft->account->id . '|' . strtolower($draft->fromAddress), $choices);
        }

        $builder
            // Unmapped. Value is a "accountId|address" token: the option the
            // user picks resolves BOTH the sending account (Drafts label +
            // IMAP drafts mailbox, via applyAccount()) and the exact From
            // address. One option per sendable alias; accounts with no aliases
            // yet contribute a single option for their display address.
            // Pre-set on render with SenderResolver::tokenFor().
            ->add('account', ChoiceType::class, $this->fromField($choices))

            // The `send` group is only applied by ComposeController::send(), so
            // a draft may be saved with no recipient at all — which is the
            // point of a draft — while an actual send may not.
            //
            // This is the server's half of the accidental-send guard, and it
            // has to be the server's: the window can be bypassed, and an
            // address that never resolved to a Contact arrives here as an
            // empty selection rather than as a bad string (see
            // ContactAutocompleteField, which drops what it cannot validate).
            // "No valid recipient" and "no recipient" are therefore the same
            // failure by the time the form is bound, and one rule covers both.
            //
            // Counted across To, Cc and Bcc, as the window's own guard does: a
            // mail sent only to Bcc is a normal thing to want (a list kept
            // private, a copy to oneself), and SMTP, Gmail and Graph all take
            // it. The rule sits on To so the refusal shows where the user looks
            // first.
            ->add('toAddresses', ContactAutocompleteField::class, [
                'mapped'      => false,
                'constraints' => [
                    new Callback(
                        callback: self::requireARecipient(...),
                        groups: ['send'],
                    ),
                ],
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
            ])

            // The plain-text surface, and only that: in rich mode the window
            // disables this control so it is not submitted at all, which binds
            // it to null and lets DraftPersister derive the text part from the
            // HTML as it always has. In plain-text mode the HTML body is
            // submitted empty and THIS is the message — see DraftPersister::save().
            //
            // Mapped onto Message::$bodyText, a column that already exists, so
            // plain-text mode needs no migration and survives a reload: a draft
            // with text and no HTML reopens as a plain-text draft because that
            // is precisely what it is.
            ->add('bodyText', TextareaType::class, [
                'label'    => false,
                'required' => false,
            ])

            // ── The more-options menu's two settings ──────────────────────
            //
            // Mapped, so they land on the Message the form is bound to and
            // DraftPersister persists them with everything else; the send path
            // then turns them into headers in MessageSendService::buildEmail().
            //
            // The window renders with `render_rest: false`, so neither of these
            // appears until the menu explicitly renders it — which is the point.
            // The control that sets them is a dropdown, not a form row, so the
            // markup is a hidden input the menu writes into rather than a widget
            // Symfony draws.
            //
            // EnumType rather than a plain hidden field: it is what refuses a
            // priority the enum does not define, so a hand-posted value cannot
            // reach the header builder. Absent from the POST, it binds null,
            // which is the "no opinion" state and emits no headers.
            ->add('priority', EnumType::class, [
                'label'    => false,
                'required' => false,
                'class'    => MessagePriority::class,
            ])

            // CheckboxType because a request either was made or was not; absent
            // from the POST it binds false, which is the correct reading of a
            // menu item that is not ticked.
            ->add('readReceiptRequested', CheckboxType::class, [
                'label'    => false,
                'required' => false,
            ]);

        // The same admission on the way back in. A window that opened on a
        // catch-all address posts a token no list of aliases contains, and the
        // form that receives the POST is built afresh — for a first send there
        // is no stored draft yet to read the address off. So the token itself
        // is checked, against the same rule: an active account of this user,
        // and an address at one of its catch-all domains. Anything else stays
        // exactly as refused as it was.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($user, $choices): void {
            $data  = $event->getData();
            $token = true === is_array($data) ? ($data['account'] ?? null) : null;

            if (false === is_string($token)) {
                return;
            }

            $extra = $this->catchAllChoice($user, $token, $choices);

            if ([] !== $extra) {
                $event->getForm()->add('account', ChoiceType::class, $this->fromField($choices + $extra));
            }
        });
    }

    /**
     * @param array<string,string> $choices
     *
     * @return array<string,mixed>
     */
    private function fromField(array $choices): array
    {
        return [
            'label'        => false,
            'mapped'       => false,
            'choices'      => $choices,
            'choice_value' => static fn (?string $token): string => $token ?? '',
            'attr'         => ['class' => 'compose-from-select'],
        ];
    }

    /**
     * The one extra From option a catch-all address needs, or nothing.
     *
     * Nothing when the token is already an option, when it does not name an
     * active account of this user, or when its address is not at one of that
     * account's catch-all domains (Account::catchesAddress()). The token comes
     * from the browser on a submit, so this is the authorisation for sending
     * as an address no alias row names.
     *
     * @param array<string,string> $choices
     *
     * @return array<string,string>  label => token, at most one entry
     */
    private function catchAllChoice(UserInterface $user, string $token, array $choices): array
    {
        if (true === in_array($token, $choices, true) || false === str_contains($token, '|')) {
            return [];
        }

        [$id, $address] = explode('|', $token, 2);

        if (false === ctype_digit($id) || $address !== AddressHelper::email($address) || false === AddressHelper::isValidEmail($address)) {
            return [];
        }

        $account = $this->accountRepository->find((int) $id);

        if (
            null === $account
            || $account->usr !== $user
            || false === (bool) $account->isActive
            || false === $account->catchesAddress($address)
        ) {
            return [];
        }

        return [$this->fromLabel($address, $account->senderNameFor($address)) => $token];
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
                $choices[$this->fromLabel($alias->address, $account->senderNameFor($alias->address))]
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

    /**
     * Refuse a send with nobody in To, Cc or Bcc.
     *
     * Read off the bound form rather than off the Message: the three fields are
     * unmapped, and the controller copies them onto the entity only after
     * validation has run.
     */
    private static function requireARecipient(mixed $to, ExecutionContextInterface $context): void
    {
        $field = $context->getObject();

        if (false === $field instanceof FormInterface || null === $field->getParent()) {
            return;
        }

        $form = $field->getParent();

        foreach (['toAddresses', 'ccAddresses', 'bccAddresses'] as $name) {
            $selected = $form->get($name)->getData();

            if (true === is_countable($selected) && count($selected) > 0) {
                return;
            }
        }

        $context->buildViolation('compose.recipient_required')->addViolation();
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
