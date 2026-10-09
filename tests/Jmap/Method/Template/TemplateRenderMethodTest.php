<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Template;

use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Jmap\Method\MethodRegistry;
use App\Jmap\Method\Template\TemplateRenderMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * `Template/render`: what a JMAP client inserts when the user picks a template.
 *
 * The thing a client cannot be left to do is the thing this method does. The
 * stored body has `{{tokens}}` in it, and turning them into values needs the
 * user's clock, the signature rules and the token grammar — three things that
 * would each be a second implementation on a phone. So the client names the
 * account it is writing from and, if it has one, the recipient, and gets back
 * a message and an honest list of what is still open.
 */
final class TemplateRenderMethodTest extends JmapTestCase
{
    private TemplateRenderMethod $render;

    protected function setUp(): void
    {
        parent::setUp();

        $this->render = self::getContainer()->get(TemplateRenderMethod::class);
    }

    public function testWithNoRecipientTheRecipientVariablesComeBackOpenAndNamed(): void
    {
        $template = $this->template(
            '<p>Hi {{recipient.first_name}}, from {{sender.email}}</p><p>{{signature}}</p>',
            'For {{recipient.name}}',
        );
        $this->account->setSetting(Account::SETTING_SIGNATURE, '<p>Regards</p>');
        $this->em->flush();

        $result = $this->render->handle(['id' => (string) $template->id, 'accountId' => $this->accountId()], $this->context());

        self::assertSame('For {{recipient.name}}', $result['subject']);
        self::assertSame(
            sprintf(
                '<p>Hi <span data-pl-var="recipient.first_name">First name</span>, from %s</p>'
                . '<div class="pl-signature" data-pl-signature><p>Regards</p></div>',
                $this->account->email,
            ),
            $result['htmlBody'],
        );
        self::assertSame(
            sprintf("Hi {{recipient.first_name}}, from %s\nRegards", $this->account->email),
            $result['textBody'],
            'in text an open variable is its token: there is no span to keep it in',
        );
        self::assertSame(['recipient.first_name', 'recipient.name'], $result['openVariables']);
    }

    public function testAGivenRecipientIsFilledInAndNothingStaysOpen(): void
    {
        $template = $this->template('<p>Hi {{recipient.first_name}} ({{recipient.email}})</p>', 'For {{recipient.name}}');

        $result = $this->render->handle([
            'id'        => (string) $template->id,
            'accountId' => $this->accountId(),
            'recipient' => ['name' => 'Whitfield, Dana', 'email' => 'dana@example.org'],
        ], $this->context());

        self::assertSame('For Dana Whitfield', $result['subject']);
        self::assertSame('<p>Hi Dana (dana@example.org)</p>', $result['htmlBody']);
        self::assertSame([], $result['openVariables']);
    }

    /** An address with no name answers the address and leaves the name open. */
    public function testARecipientWithNoNameLeavesTheNameOpenRatherThanGuessingOne(): void
    {
        $template = $this->template('<p>Hi {{recipient.first_name}} ({{recipient.email}})</p>');

        $result = $this->render->handle([
            'id'        => (string) $template->id,
            'accountId' => $this->accountId(),
            'recipient' => ['email' => 'dana.whitfield@example.org'],
        ], $this->context());

        self::assertStringContainsString('(dana.whitfield@example.org)', $result['htmlBody']);
        self::assertStringNotContainsString('Hi dana', $result['htmlBody']);
        self::assertSame(['recipient.first_name'], $result['openVariables']);
    }

    public function testAnAccountIsRequiredAndMustBeTheCallers(): void
    {
        $template = $this->template('<p>x</p>');

        foreach ([[], ['accountId' => '2147483000']] as $extra) {
            try {
                $this->render->handle(['id' => (string) $template->id] + $extra, $this->context());
                self::fail('rendered without an account of the caller\'s');
            } catch (MethodException $exception) {
                self::assertContains($exception->toError()['type'], ['invalidArguments', 'accountNotFound']);
            }
        }
    }

    public function testAnUnknownTemplateOrIdentityIsRefused(): void
    {
        $template = $this->template('<p>x</p>');

        foreach ([
            ['id' => '2147483000', 'accountId' => $this->accountId()],
            ['id' => (string) $template->id, 'accountId' => $this->accountId(), 'identityId' => 'no-such-identity'],
        ] as $arguments) {
            try {
                $this->render->handle($arguments, $this->context());
                self::fail('rendered something that does not exist');
            } catch (MethodException $exception) {
                self::assertSame('invalidArguments', $exception->toError()['type']);
            }
        }
    }

    // ── the capability ───────────────────────────────────────────────────────

    /**
     * The Session publishes the vocabulary an editor has to offer, and every
     * method the capability stands for is registered. An advertised method
     * that is not there is a client's first request failing.
     */
    public function testTheSessionAdvertisesTheCapabilityItsVocabularyAndItsMethods(): void
    {
        $container = self::getContainer();
        $session   = $container->get(SessionBuilder::class)->build($this->user);

        self::assertContains(Capability::TEMPLATES, Capability::SUPPORTED);

        $templates = $session['capabilities'][Capability::TEMPLATES];

        self::assertContains(
            ['name' => 'recipient.first_name', 'group' => 'recipient', 'filledBy' => 'recipient'],
            $templates['variables'],
        );
        self::assertContains(['name' => 'date', 'group' => 'date', 'filledBy' => 'server'], $templates['variables']);
        self::assertSame(['short', 'medium', 'long', 'full', 'weekday', 'iso'], $templates['dateFormats']);

        foreach ($session['accounts'] as $account) {
            self::assertArrayNotHasKey(Capability::TEMPLATES, $account['accountCapabilities'], 'templates are the user\'s, not an account\'s');
        }

        foreach (['Template/get', 'Template/set', 'Template/render', 'TemplateFolder/get', 'TemplateFolder/set'] as $name) {
            self::assertNotNull($container->get(MethodRegistry::class)->get($name), sprintf('"%s" is not registered', $name));
        }
    }

    private function template(string $body, ?string $subject = null): MailTemplate
    {
        $template          = new MailTemplate();
        $template->usr     = $this->user;
        $template->name    = 'Fixture';
        $template->subject = $subject;
        $template->body    = $body;

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }
}
