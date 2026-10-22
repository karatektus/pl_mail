<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Enum\User\ApiTokenScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Naming a new app password.
 *
 * Not mapped to ApiToken: the entity's factory mints the secret and the hash
 * together, so the form only carries the label the user types. The reason it
 * is a form type at all is the CSRF token — this endpoint mints a working
 * credential, and it used to accept a bare cross-site POST.
 */
final class ApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            // Rendered as an inline row next to the Generate button; the
            // placeholder and the button already name the field.
            'label' => false,
            'attr'  => [
                'maxlength'   => 100,
                'placeholder' => 'settings.app_passwords.name_placeholder',
            ],
            'constraints' => [
                new NotBlank(message: 'app_password.name_required'),
                new Length(max: 100),
            ],
        ]);

        // ── What the credential may do ─────────────────────────────────────
        // Three boxes, not a dropdown, because the useful answers are "all",
        // "read only" and one or two in between, and a person setting up an
        // automation should be able to see all of them at once.
        //
        // All three start TICKED, which is what an app password did before
        // scopes existed. The page says so beside them: unticking is the
        // deliberate act, not ticking.
        //
        // Unmapped — the entity grants through ApiToken::create()'s scope
        // argument, and this form only collects the answer.
        foreach (ApiTokenScope::all() as $scope) {
            $builder->add('scope_' . $scope->value, CheckboxType::class, [
                'label'   => $scope->labelKey(),
                'mapped'  => false,
                'required'=> false,
                'data'    => true,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'api_token_create',
        ]);
    }
}
