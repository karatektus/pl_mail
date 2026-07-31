<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\Setup\FirstAdminType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * Password managers may only touch fields that are actually credentials.
 *
 * They classify by `autocomplete`, so `given-name` on a name field reads to
 * Bitwarden as an identity form: on a fresh install it drew its "new identity"
 * overlay straight into "First name", on a screen that is creating the first
 * administrator of a self-hosted mail server. Reported from a real install.
 *
 * The theme applies the opt-out to every text field and withdraws it only for
 * the real credentials. Both halves are asserted here — the second matters just
 * as much, because suppressing the extension on the sign-in form would stop it
 * offering to save the one password the user does want kept.
 */
final class PasswordManagerIgnoreTest extends KernelTestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function fieldProvider(): iterable
    {
        // field name => should the extensions be told to keep out?
        yield 'first name is not a credential'  => ['nameFirst', true];
        yield 'last name is not a credential'   => ['nameLast', true];
        yield 'public URL is a server address'  => ['publicUrl', true];
        yield 'email is the sign-in identifier' => ['email', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fieldProvider')]
    public function testOnlyCredentialFieldsAreOfferedToPasswordManagers(
        string $field,
        bool $expectIgnored,
    ): void {
        self::bootKernel();

        $html = self::renderField($field);

        if ($expectIgnored) {
            self::assertStringContainsString('data-bwignore', $html, "$field must be ignored");
            self::assertStringContainsString('data-1p-ignore', $html, "$field must be ignored");

            return;
        }

        self::assertStringNotContainsString(
            'data-bwignore',
            $html,
            "$field is a credential and must stay available to password managers",
        );
    }

    public function testPasswordFieldStaysAvailableToPasswordManagers(): void
    {
        self::bootKernel();

        // Rendered from the repeated type's first child, which is where the
        // new-password hint lives.
        $html = self::renderField('plainPassword');

        self::assertStringContainsString('new-password', $html);
        self::assertStringNotContainsString(
            'data-bwignore',
            $html,
            'the install password is exactly what a manager should offer to save',
        );
    }

    /**
     * The fields above also carry the attributes from their form type, so on
     * their own they cannot tell whether the theme is doing anything. This one
     * declares nothing at all: if it comes back ignored, every text field in the
     * app is covered by default, which is the actual requirement — new forms
     * should not have to remember.
     */
    public function testThemeCoversAFieldThatDeclaresNothing(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $form = $container->get(FormFactoryInterface::class)
            ->createBuilder(FormType::class, null, ['csrf_protection' => false])
            ->add('anything', TextType::class)
            ->getForm()
            ->createView();

        $html = $container->get(Environment::class)
            ->createTemplate('{{ form_widget(field) }}')
            ->render(['field' => $form['anything']]);

        self::assertStringContainsString('data-bwignore', $html);
        self::assertStringContainsString('data-form-type', $html);
    }

    private static function renderField(string $field): string
    {
        $container = self::getContainer();

        $form = $container->get(FormFactoryInterface::class)
            ->create(FirstAdminType::class, null, ['csrf_protection' => false])
            ->createView();

        return $container->get(Environment::class)
            ->createTemplate('{{ form_widget(field) }}')
            ->render(['field' => $form[$field]]);
    }
}
