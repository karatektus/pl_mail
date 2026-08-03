<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Entity\User\User;
use Symfony\Component\Form\AbstractType;
use App\Domain\Helper\AvatarStorage;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The user's own details.
 *
 * Lives under Form\User rather than Form\Onboarding because the wizard is not
 * its only home: Settings → Profile renders the same form. A step that is the
 * only way to reach a feature is a trap — skip it once and the feature is gone.
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nameFirst', TextType::class, [
                'label'       => 'profile.field.name_first',
                'constraints' => [new NotBlank()],
                'attr'        => ['autocomplete' => 'given-name'],
            ])
            ->add('nameLast', TextType::class, [
                'label'       => 'profile.field.name_last',
                'constraints' => [new NotBlank()],
                'attr'        => ['autocomplete' => 'family-name'],
            ])
            // Unmapped: the entity stores a filename, and what arrives here is
            // a file. AvatarStorage turns one into the other.
            ->add('avatarFile', FileType::class, [
                'label'       => 'profile.field.avatar',
                'help'        => 'profile.field.avatar_help',
                'mapped'      => false,
                'required'    => false,
                'constraints' => [
                    new File(
                        maxSize: AvatarStorage::MAX_BYTES,
                        mimeTypes: AvatarStorage::ALLOWED_MIME,
                        mimeTypesMessage: 'profile.avatar_wrong_type',
                    ),
                ],
                'attr' => ['accept' => implode(',', AvatarStorage::ALLOWED_MIME)],
            ]);

        // Present only while pictures from a connected service are on screen.
        // The file id is set by the thumbnail the user clicks — each is a submit
        // button carrying this field's name — so the choice and the submission
        // are one action rather than two.
        if (null !== $options['avatar_source']) {
            $builder
                ->add('avatarIntegrationId', HiddenType::class, [
                    'mapped' => false,
                    'data'   => $options['avatar_source'],
                ])
                ->add('avatarFileId', HiddenType::class, ['mapped' => false]);
        }

        // Offered only when there is one to remove, so it cannot read as
        // "tick this to delete the picture I have not got".
        if (null !== $options['data']?->avatar) {
            $builder->add('removeAvatar', CheckboxType::class, [
                'label'    => 'profile.field.avatar_remove',
                'mapped'   => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => User::class, 'avatar_source' => null])
            ->setAllowedTypes('avatar_source', ['null', 'string']);
    }
}
