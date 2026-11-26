<?php

declare(strict_types=1);

namespace App\Form\Integration;

use App\Form\PasswordManagerIgnore;
use App\Service\Integration\TrustedHostList;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Admin → Integrations → Servers on your network: one host to allow.
 *
 * Unmapped array data (`host`, `allowHttp`) rather than the entity, because
 * the typed text is not the stored value — a whole address is accepted and cut
 * down to its host by TrustedHostList::normalise(), which the controller calls
 * once this has said the text has a host in it.
 */
final class TrustedHostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('host', TextType::class, [
                'label'       => 'admin.integrations.trusted.field.host',
                'help'        => 'admin.integrations.trusted.field.host_help',
                'attr'        => ['placeholder' => 'dav.home.example.com', 'autocomplete' => 'off'] + PasswordManagerIgnore::ATTR,
                'constraints' => [
                    new NotBlank(message: 'admin.integrations.trusted.host_invalid'),
                    // A whole URL is allowed in, so the column's 253 is not the
                    // input's limit; this only keeps a paste of a page from
                    // reaching the parser.
                    new Length(max: 2048),
                    new Callback(static function (?string $typed, ExecutionContextInterface $context): void {
                        if (null !== $typed && '' !== trim($typed) && null === TrustedHostList::normalise($typed)) {
                            $context->buildViolation('admin.integrations.trusted.host_invalid')->addViolation();
                        }
                    }),
                ],
            ])
            ->add('allowHttp', CheckboxType::class, [
                'label'    => 'admin.integrations.trusted.field.allow_http',
                'help'     => 'admin.integrations.trusted.field.allow_http_help',
                'required' => false,
            ]);
    }
}
