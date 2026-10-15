<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A credential pasted out of somebody else's console — a client secret, an API
 * token, a mail server's password — that is NOT the login for this site.
 *
 * A text input drawn as dots, and deliberately not `type="password"`. A
 * password input is what browsers and password managers look for, and no
 * attribute talks all of them out of it:
 *
 *  - `autocomplete="off"` is ignored on a password field by Chrome, which
 *    filled the administrator's own mail address and password into Admin → SSO
 *    as its client ID and secret.
 *  - `autocomplete="new-password"` stopped that fill and started two other
 *    things: on a click Chrome still listed every saved password, and Bitwarden
 *    read the form as a sign-up, offering to generate a password into the field
 *    and to fill logins into the text fields after it.
 *  - The vendor attributes in PasswordManagerIgnore stop the extensions
 *    filling, and did not stop Bitwarden's menu from opening.
 *
 * With no password input in the form there is nothing for any of them to
 * classify. The dots come from CSS (`.secret-masked`, app.css), which is what
 * the field needs from a password input and all it needs: the value is not
 * readable over a shoulder or in a screen share.
 *
 * What is given up is the browser refusing to copy out of the field. Nothing
 * here relies on it — the value is whatever was just pasted in, and a stored
 * one is never rendered: like PasswordType with `always_empty`, the field comes
 * back blank even when the form is re-displayed with an error.
 */
final class SecretType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['value'] = '';

        $view->vars['attr'] = [
            ...$view->vars['attr'],
            ...PasswordManagerIgnore::ATTR,
            // Not the field's to choose: anything but `off` is an invitation.
            'autocomplete' => 'off',
            'spellcheck'   => 'false',
            'class'        => trim(($view->vars['attr']['class'] ?? '') . ' secret-masked'),
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // A secret may begin or end with a space; PasswordType does not trim
        // either, and a field that silently changed what was pasted would be
        // refused by the provider with nothing to say why.
        $resolver->setDefaults(['trim' => false]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
