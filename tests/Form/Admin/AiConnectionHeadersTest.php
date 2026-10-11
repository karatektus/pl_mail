<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Domain\Ai\ConnectionHeaders;
use App\Entity\Ai\AiSettings;
use App\Form\Admin\AiSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * What happens to the stored extra headers when the form is saved.
 *
 * The same shape as the token, and for the same reason: the values are never
 * put back on the page, so the box arrives empty on every save that did not
 * retype them. Empty has to mean "leave them", a changed address has to mean
 * "drop them", and removing them on purpose needs a gesture of its own.
 */
final class AiConnectionHeadersTest extends KernelTestCase
{
    private const string STORED = '{"X-Tenant":"stored-secret"}';

    private FormFactoryInterface $forms;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->forms = self::getContainer()->get('form.factory');
    }

    public function testAnEmptyBoxKeepsWhatIsStored(): void
    {
        [$settings] = $this->submit([]);

        self::assertSame(self::STORED, $settings->headers);
    }

    /** All of them, not a merge: nobody can see the old ones to merge against. */
    public function testWhatIsTypedReplacesTheStoredSet(): void
    {
        [$settings, $form] = $this->submit(['headers' => "X-Gateway-Key: fresh\nX-Project: mail"]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true, false));
        self::assertSame(['X-Gateway-Key' => 'fresh', 'X-Project' => 'mail'], ConnectionHeaders::decode($settings->headers));
    }

    public function testTheTickBoxRemovesThem(): void
    {
        [$settings] = $this->submit(['clearHeaders' => '1']);

        self::assertNull($settings->headers);
    }

    /** Typed beats ticked: a new set was written, so a new set is what is meant. */
    public function testTypingANewSetWinsOverTheTickBox(): void
    {
        [$settings] = $this->submit(['clearHeaders' => '1', 'headers' => 'X-Project: mail']);

        self::assertSame(['X-Project' => 'mail'], ConnectionHeaders::decode($settings->headers));
    }

    /**
     * A header value is a credential for whatever answers at the address. A new
     * address is something else answering.
     */
    public function testAChangedAddressDropsThem(): void
    {
        [$settings] = $this->submit(['baseUrl' => 'https://somewhere.else.example']);

        self::assertNull($settings->headers);
    }

    public function testAChangedProviderDropsThem(): void
    {
        [$settings] = $this->submit(['provider' => 'openai']);

        self::assertNull($settings->headers);
    }

    /**
     * Refused on the field, with a reason — and the stored set untouched, so a
     * typo cannot cost a working configuration.
     */
    public function testTextThatIsNotHeadersIsRefusedAndChangesNothing(): void
    {
        [$settings, $form] = $this->submit(['headers' => 'Content-Type: text/plain']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('headers')->getErrors());
        self::assertSame(self::STORED, $settings->headers);
    }

    /** The error says what is wrong and never what was typed. */
    public function testARefusalDoesNotQuoteTheValue(): void
    {
        [, $form] = $this->submit(['headers' => "X-Key: a-secret-value\nno colon here"]);

        self::assertFalse($form->isValid());
        self::assertStringNotContainsString('a-secret-value', (string) $form->getErrors(true, false));
    }

    /**
     * The page names what is stored and shows none of it.
     *
     * The names are how an administrator knows the box is not really empty; the
     * values are the part that must not come back.
     */
    public function testThePageNamesTheStoredHeadersAndShowsNoValue(): void
    {
        $form = $this->forms->create(AiSettingsType::class, $this->stored(), ['csrf_protection' => false]);
        $view = $form->createView()->children['headers'];

        self::assertSame('', (string) $view->vars['value']);
        self::assertSame('admin.ai.field.headers_help_set', $view->vars['help']);
        self::assertSame(['%names%' => 'X-Tenant'], $view->vars['help_translation_parameters']);
    }

    /** The search host has headers of its own, under the same rules. */
    public function testTheSearchHostsHeadersAreItsOwn(): void
    {
        $settings = $this->stored();
        $settings->embeddingBaseUrl = 'http://10.0.0.9:11434';
        $settings->embeddingHeaders = '{"X-Home":"stored"}';

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);
        $form->submit([
            'provider'               => 'ollama',
            'baseUrl'                => 'http://10.0.0.5:11434',
            'clearHeaders'           => '1',
            'embeddingOwnConnection' => '1',
            'embeddingProvider'      => 'ollama',
            'embeddingBaseUrl'       => 'http://10.0.0.9:11434',
        ], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true, false));
        self::assertNull($settings->headers, 'removed, as asked');
        self::assertSame('{"X-Home":"stored"}', $settings->embeddingHeaders, 'the other host\'s were not asked about');

        $view = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false])->createView();

        self::assertSame(['%names%' => 'X-Home'], $view->children['embeddingHeaders']->vars['help_translation_parameters']);
        self::assertSame('', (string) $view->children['embeddingHeaders']->vars['value']);
    }

    /** The short form has no second host in it at all. */
    public function testTheShortFormHasNoSecondHost(): void
    {
        $form = $this->forms->create(AiSettingsType::class, $this->stored(), ['csrf_protection' => false, 'advanced' => false]);

        foreach (['embeddingOwnConnection', 'embeddingProvider', 'embeddingBaseUrl', 'embeddingApiToken', 'embeddingHeaders', 'clearEmbeddingHeaders'] as $field) {
            self::assertFalse($form->has($field), $field);
        }
    }

    /**
     * The setup wizard uses the short form, without these fields — and saving
     * it must not be what removes headers somebody set up in Admin → AI.
     */
    public function testTheShortFormLeavesTheFieldsOutAndTheHeadersAlone(): void
    {
        $settings = $this->stored();

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false, 'advanced' => false]);

        self::assertFalse($form->has('headers'));
        self::assertFalse($form->has('clearHeaders'));

        $form->submit(['provider' => 'ollama', 'baseUrl' => 'http://10.0.0.5:11434', 'chatModel' => 'a-model'], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true, false));
        self::assertSame(self::STORED, $settings->headers);
    }

    /** They still belong to the address, whichever form changed it. */
    public function testTheShortFormStillDropsThemWhenTheAddressChanges(): void
    {
        $settings = $this->stored();

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false, 'advanced' => false]);
        $form->submit(['provider' => 'ollama', 'baseUrl' => 'https://somewhere.else.example'], false);

        self::assertNull($settings->headers);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{AiSettings, FormInterface}
     */
    private function submit(array $fields): array
    {
        $settings = $this->stored();

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);

        $form->submit(['provider' => 'ollama', 'baseUrl' => 'http://10.0.0.5:11434', ...$fields], false);

        return [$settings, $form];
    }

    private function stored(): AiSettings
    {
        $settings = new AiSettings();
        $settings->baseUrl = 'http://10.0.0.5:11434';
        $settings->headers = self::STORED;

        return $settings;
    }
}
