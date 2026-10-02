<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\PasswordManagerIgnore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

/**
 * The public address, on its own — the field the setup screen asks for among
 * the first administrator's details, for the administrator who needs to change
 * it afterwards.
 *
 * The same rules as there, deliberately: FirstAdminType explains why the TLD is
 * not required, and an address setup accepted must not be one this form then
 * refuses to save back.
 */
final class PublicUrlType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('publicUrl', UrlType::class, [
            'label'            => 'admin.address.field.public_url',
            'help'             => 'admin.address.field.public_url_help',
            'default_protocol' => null,
            'constraints'      => [new NotBlank(), new Url(requireTld: false)],
            // A server address, not an account of any kind.
            'attr'             => PasswordManagerIgnore::ATTR,
        ]);
    }
}
