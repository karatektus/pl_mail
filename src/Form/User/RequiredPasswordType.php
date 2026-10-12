<?php

declare(strict_types=1);

namespace App\Form\User;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Choosing your own password because somebody else chose the current one.
 *
 * ChangePasswordType without the current password, and that omission is the
 * whole difference. There it stops a borrowed session from locking the owner
 * out; here the current password is one an administrator typed and the
 * person was handed a moment ago, so asking for it again proves nothing a
 * second time and costs the one thing this flow has to be — quick. The form is
 * reachable only while User::$passwordChangeRequired is set, which
 * RequiredPasswordController checks before it renders or accepts anything.
 */
final class RequiredPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('new', NewPasswordType::class, [
            'label'  => false,
            'mapped' => false,
        ]);
    }
}
