<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Oidc\OidcConfig;
use App\Form\Admin\OidcConfigType;
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
        // Bitwarden opened its menu in exactly the text fields that had no
        // `autocomplete` at all, and left alone the ones that said `off`.
        self::assertStringContainsString('autocomplete="off"', $html);
    }

    /**
     * A pasted secret is not a password input, and nothing in src/Form may make
     * one of it again.
     *
     * Three attempts at talking browsers and password managers out of a
     * `type="password"` field each fixed one symptom: `off` is ignored by
     * Chrome, which filled the administrator's own login into Admin → SSO;
     * `new-password` stopped that and had Bitwarden offer to generate a
     * password into the field and Chrome list every saved one on a click. The
     * field is a SecretType now — text, drawn as dots — so there is no password
     * input left to classify.
     */
    public function testAPastedSecretIsAMaskedTextFieldThatComesBackEmpty(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $form = $container->get(FormFactoryInterface::class)
            ->create(OidcConfigType::class, new OidcConfig(), ['csrf_protection' => false]);
        $form->submit(['issuer' => 'https://auth.example.com/', 'clientSecret' => ' typed-secret ']);

        self::assertSame(' typed-secret ', $form->get('clientSecret')->getData(), 'a secret is taken as pasted, spaces included');

        $html = $container->get(Environment::class)
            ->createTemplate('{{ form_widget(field) }}')
            ->render(['field' => $form->createView()['clientSecret']]);

        self::assertStringContainsString('type="text"', $html);
        self::assertStringContainsString('autocomplete="off"', $html);
        self::assertStringContainsString('secret-masked', $html);
        self::assertStringContainsString('data-bwignore', $html);
        self::assertStringNotContainsString('typed-secret', $html, 'what was typed is not rendered back, even after a refusal');
    }

    /**
     * The only password inputs left are the ones somebody signs in with or
     * sets a login password through. Anything else in a form is a SecretType.
     */
    public function testOnlyLoginPasswordsArePasswordInputs(): void
    {
        $logins = ['FirstAdminType.php', 'UserFormType.php', 'ChangePasswordType.php'];
        $others = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src/Form')) as $file) {
            if (false === $file->isFile() || 'php' !== $file->getExtension() || in_array($file->getFilename(), $logins, true)) {
                continue;
            }

            // Not preceded by a letter, so ChangePasswordType::class is not it.
            if (1 === preg_match('/(?<![A-Za-z])PasswordType::class/', (string) file_get_contents($file->getPathname()))) {
                $others[] = $file->getFilename();
            }
        }

        self::assertSame([], $others, 'These forms use a password input for something that is not this site\'s login. Use App\Form\SecretType, or a browser fills in the saved login.');
    }

    /**
     * Nothing follows an input as a sibling — its help text least of all.
     *
     * Bitwarden takes the text after a field as part of the field's label. The
     * help text was the next element after the input, so a help that explained
     * the `email` scope made Scopes an identity's email address to it, and it
     * opened its menu there whatever the field's own attributes said.
     */
    public function testHelpTextIsNotTheInputsNextSibling(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $form = $container->get(FormFactoryInterface::class)
            ->create(OidcConfigType::class, new OidcConfig(), ['csrf_protection' => false])
            ->createView();

        $html = $container->get(Environment::class)
            ->createTemplate('{{ form_row(field) }}')
            ->render(['field' => $form['scopes']]);

        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        self::assertGreaterThan(0, $crawler->filter('p')->count(), 'the field under test has to have help text');
        self::assertSame(0, $crawler->filter('input')->nextAll()->count());
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
